<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Application\Listeners\ReturnAPaidChangeWhoseDeliveryStopped;
use Lynomia\Modules\Subscriptions\Application\Queries\PlanChangeDelivery;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * Returns to the wallet an upgrade paid for and never delivered because the
 * subscription, or the service it changes, ended.
 *
 * An upgrade is delivered by the resize its settlement queues
 * ({@see ResizeOnPlanChangeSettlement}, keyed `plan-change:<subscription>:<plan>:invoice:<id>`
 * by QueuePlanChangeAtProvider). Two ends leave it undelivered:
 *
 *  - A settlement heard after the subscription ended queues nothing (O-1) -
 *    the machine is being switched off - and the money used to be kept, "an
 *    operator's to refund", with a log line. That is money for nothing, and
 *    it is not kept (OA-3, the coordinator's ruling on round four's
 *    re-audit).
 *  - A settlement heard while the subscription was live queued the resize,
 *    and the resize stopped - in review (a node that could no longer hold
 *    the growth, a resize that could not be confirmed), failed outright, or
 *    closed - and then its service ended. A retry is refused once the
 *    service has ended and a close does not run the job, so nothing will
 *    deliver it. It used to count as delivered because its settlement had
 *    been heard (`delivered_at`), and the payment was kept while the customer
 *    had been told it was held "until the change is applied, or returned if
 *    it cannot be" (R10-M, the final audit).
 *
 * Either way what the invoice still holds (WhatAnInvoiceStillHolds) goes back
 * to the wallet, recorded against the invoice, through
 * ReturnWhatAnInvoiceStillHolds.
 *
 * Asked from every side of the race, and idempotent between them (the return
 * reads what the invoice holds under its lock, so a second call credits
 * nothing): the wind-up of the ended subscription
 * ({@see WindUpAnEndedSubscription}), which runs again when the service is
 * terminated after the subscription ended; the settlement heard after the end,
 * for one the wind-up did not see; and the stop of the delivering job - its
 * failure or its move to review on a service already ended, and its close
 * ({@see ReturnAPaidChangeWhoseDeliveryStopped}).
 *
 * Returned only when all of these hold, and otherwise left alone:
 *  - the invoice is paid and carries a proration line (an upgrade's, not a
 *    renewal's);
 *  - it was not delivered (PlanChangeDelivery::wasDelivered()), and one of:
 *     - its delivering job stopped without succeeding on a service that has
 *       ended (PlanChangeDelivery::deliveryEndedWithItsService()) - whatever
 *       the subscription's state, since a subscription can outlive one of
 *       its services, or not yet have heard that it ended;
 *     - the subscription has ended and its settlement was not heard while it
 *       was live (PlanChange::$delivered_at, stamped under the subscription's
 *       lock; for a change settled before `delivered_at` existed, and a
 *       proration invoice with no recorded change, a resize or package-change
 *       job keyed on the invoice). A settlement that queued nothing because
 *       nothing needed resizing delivered the upgrade all the same: it used
 *       to be read off whether a resize job existed, and such an upgrade was
 *       returned at the end;
 *  - no later change was settled, when the change is recorded: an upgrade a
 *    later settled change superseded - a later change that owed nothing, or
 *    whose invoice was paid, and whose credit was drawn on this invoice -
 *    was settled by that change, not left undelivered. A later change still
 *    unpaid (voided as the subscription ends) supersedes nothing, and the
 *    upgrade is returned (X1).
 *
 * A change its settlement found could no longer be delivered was returned
 * there (ReturnAPlanChangeNoLongerDeliverable): the invoice holds nothing
 * more, and asked again here it credits nothing. So does an invoice an
 * operator already refunded by hand.
 *
 * A return that credits something is recorded as that one does: the change's
 * `returned_at` and `return_reason`; a `subscription.plan_changed` audit
 * entry with the reason AUDIT_REASON, naming the invoice, the change and what
 * was returned (the plan is not put back: the subscription or the service has
 * ended); a log line; and the customer is told, once the transaction commits
 * (`billing.plan_change_returned_at_the_end`), once per change. A call that
 * credits nothing records nothing.
 *
 * No lock of its own beyond the invoice's and the wallet's (inside
 * ReturnWhatAnInvoiceStillHolds); the caller's come first
 * (WhatAnInvoiceStillHolds, the lock order). No provider is called.
 */
final readonly class ReturnAnUpgradeTheEndPrevented
{
    public const string AUDIT_REASON = 'plan_change_not_delivered_before_the_end';

    public function __construct(
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
        private PlanChangeDelivery $delivery,
        private RecordAuditEntry $audit,
        private NotifyCustomer $notify,
    ) {}

    /**
     * @return int the minor units this call credited; zero when nothing is returned
     */
    public function execute(string $invoiceId): int
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->find($invoiceId);

        if ($invoice === null || $invoice->status !== InvoiceStatus::Paid || $invoice->subscription_id === null) {
            return 0;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->find($invoice->subscription_id);

        if ($subscription === null) {
            return 0;
        }

        $isAnUpgrade = InvoiceItem::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('kind', InvoiceItemKind::Proration->value)
            ->exists();

        if (! $isAnUpgrade) {
            return 0;
        }

        $subscriptionId = (string) $subscription->getKey();

        if ($this->delivery->wasDelivered($subscriptionId, (string) $invoice->getKey())) {
            return 0;
        }

        // Not delivered. Settled, it can only be here because its job stopped
        // on a service that has ended; not settled, only once the
        // subscription has ended (the class docblock).
        $stopped = $this->delivery->deliveryEndedWithItsService($subscriptionId, (string) $invoice->getKey());

        if (! $stopped && ! $subscription->status->isTerminal()) {
            return 0;
        }

        /** @var PlanChange|null $change */
        $change = PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->first();

        if ($change !== null && $this->delivery->aLaterChangeWasSettled($change)) {
            return 0;
        }

        $why = $stopped
            ? 'its resize or package change stopped without completing, and its service has ended'
            : 'the subscription ended before the change was delivered';

        $credited = $this->returnWhatItHolds->toTheWallet(
            $invoice,
            'upgrade-not-delivered',
            sprintf('Payment for invoice %s returned: the upgrade was not delivered before the service ended', $invoice->number),
            ['subscription_id' => $subscriptionId, 'plan_change_id' => $change?->getKey()],
        );

        if ($credited <= 0) {
            return 0;
        }

        if ($change !== null) {
            PlanChange::query()
                ->whereKey($change->getKey())
                ->whereNull('returned_at')
                ->update(['returned_at' => now(), 'return_reason' => $why]);
        }

        $this->audit->execute(
            action: AuditAction::PlanChanged,
            subject: $subscription,
            customerId: $subscription->customer_id,
            context: [
                'from_plan_id' => $change?->to_plan_id,
                'to_plan_id' => $change?->to_plan_id,
                'reason' => self::AUDIT_REASON,
                'why' => $why,
                'plan_restored' => false,
                'proration_invoice_id' => (string) $invoice->getKey(),
                'plan_change_id' => $change === null ? null : (string) $change->getKey(),
                'returned_to_wallet_minor' => $credited,
            ],
        );

        Log::info('An upgrade paid for and never delivered, because its subscription or its service ended, was returned to the wallet.', [
            'invoice_id' => (string) $invoice->getKey(),
            'subscription_id' => $subscriptionId,
            'why' => $why,
            'credited_minor' => $credited,
        ]);

        $this->tellTheCustomerOnceCommitted($subscription, $change, $invoice, $credited);

        return $credited;
    }

    /**
     * After the commit, as ReturnAPlanChangeNoLongerDeliverable tells its
     * customer: a message about a return that rolled back would tell the
     * customer of money that never moved. Keyed on the change (or, for an
     * invoice with no recorded change, on the invoice), so one change is
     * announced as returned once.
     */
    private function tellTheCustomerOnceCommitted(Subscription $subscription, ?PlanChange $change, Invoice $invoice, int $credited): void
    {
        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();
        $label = $service?->label;
        $name = $service === null
            ? ['en' => 'your service', 'ar' => 'خدمتك']
            : (is_string($label) && $label !== '' ? $label : (string) $service->getKey());
        $amount = Money::ofMinor($credited, (string) $invoice->currency)->format();
        $key = 'plan-change-returned:'.($change === null ? 'invoice:'.$invoice->getKey() : $change->getKey());

        DB::afterCommit(function () use ($subscription, $key, $name, $amount): void {
            $this->notify->execute(
                customerId: (string) $subscription->customer_id,
                type: NotificationType::PlanChangeReturnedAtTheEnd,
                idempotencyKey: $key,
                subject: $subscription,
                data: ['service' => $name, 'amount' => $amount],
                link: '/subscriptions',
            );
        });
    }
}
