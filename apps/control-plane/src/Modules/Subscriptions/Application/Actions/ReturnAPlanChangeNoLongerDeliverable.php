<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Orders\Application\Services\PlanCapacity;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Application\Queries\PlanChangeDelivery;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * Returns a paid plan change that can no longer be delivered, instead of
 * delivering nothing and keeping the money.
 *
 * The rule: a paid plan change its settlement finds can no longer be
 * delivered goes back. Only that: a change that fails after its settlement
 * (a resize or package change that fails outright or stops in review), or
 * whose settlement is never heard, is not returned here - its money is held
 * for an operator to complete the change or return it (docs/billing.md).
 * The payment of a proration invoice asks whether the change can still be
 * delivered (PlanChangeDelivery::refusalForTheInvoice()) when the payment is
 * opened, but a card payment is captured later, at the provider, and an
 * operator can withdraw the package, fill the node or remove the machine in
 * between. The capture is the provider's fact and the invoice is settled
 * paid; the settlement ({@see ResizeOnPlanChangeSettlement}) then asks the
 * same question again, and a change it refuses comes here. It used to be
 * recorded delivered with nothing queued, the subscription left on the plan
 * it never got and billed for it, and a log warning the only trace.
 *
 * Asked under the subscription's lock, the settlement's, so the lock order is
 * the one WhatAnInvoiceStillHolds declares for this listener: the
 * subscription, then the paid invoice and the wallet (the return), then the
 * plan the subscription goes back to (PlanCapacity::lock(), last).
 *
 *  - What the invoice still holds goes back to the wallet, recorded against
 *    the invoice (ReturnWhatAnInvoiceStillHolds - the machinery every other
 *    return of an invoice's money uses, so a card refund of the same invoice
 *    afterwards is held to what is left). Not to the card: the platform's
 *    returns of undelivered purchases are wallet credit, and a card refund
 *    stays an operator's decision on top of it.
 *  - The subscription goes back to the plan and the recurring amount the
 *    change recorded it came from, as a voided upgrade's does
 *    (RestorePlanOnVoidedUpgrade), when it is still where the change put it
 *    and no change was made after it. The machine or the account never left
 *    that plan's shape. A paid upgrade stops counting its unit against the
 *    plan it left, so that plan may have sold the unit in the window; the
 *    subscription goes back all the same, because that is what it runs. The
 *    plan's stock_limit is then exceeded, by at most the change's units, and
 *    the audit entry and the log say by how much (`plan_stock_exceeded_by`,
 *    stockExceededBy()) - it used to be exceeded silently (F-06's residue).
 *  - The change's record says it was returned and why (`returned_at`,
 *    `return_reason`), which is what keeps it from reading as a paid change
 *    awaiting delivery (PlanChangeDelivery::aPaidChangeAwaitsDelivery()).
 *  - An operator sees it: a `subscription.plan_changed` audit entry with the
 *    reason `plan_change_not_deliverable_at_settlement`, naming the invoice,
 *    the change, the refusal and what was returned, beside the log warning.
 *  - The customer is told (`billing.plan_change_returned`), once the
 *    transaction commits: the change was not made, so the service was not
 *    changed, and what was returned to the wallet. Not which plan the
 *    subscription is on: it goes back only when nothing was changed after
 *    this change, and the sentence used to say the service "stays on its
 *    current plan" of a subscription left on the plan it was returned from
 *    (N4, the re-audit after round six).
 */
final readonly class ReturnAPlanChangeNoLongerDeliverable
{
    public const string AUDIT_REASON = 'plan_change_not_deliverable_at_settlement';

    public function __construct(
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
        private RecordAuditEntry $audit,
        private PlanCapacity $capacity,
        private NotifyCustomer $notify,
    ) {}

    /**
     * @param  Subscription  $locked  the subscription, locked by the caller
     * @param  string  $refusal  why the change cannot be delivered, in an operator's words
     * @return int the minor units credited to the wallet
     */
    public function execute(Subscription $locked, PlanChange $change, Invoice $invoice, string $refusal): int
    {
        $credited = $this->returnWhatItHolds->toTheWallet(
            $invoice,
            'plan-change-not-deliverable',
            sprintf('Payment for invoice %s returned: the plan change it paid for could no longer be made', $invoice->number),
            ['subscription_id' => (string) $locked->getKey(), 'plan_change_id' => (string) $change->getKey()],
        );

        $restored = $this->restoreThePlan($locked, $change);
        $exceededBy = $restored ? $this->stockExceededBy((string) $change->from_plan_id) : 0;

        PlanChange::query()
            ->whereKey($change->getKey())
            ->whereNull('returned_at')
            ->update(['returned_at' => now(), 'return_reason' => mb_substr($refusal, 0, 500)]);

        $this->audit->execute(
            action: AuditAction::PlanChanged,
            subject: $locked,
            customerId: $locked->customer_id,
            context: [
                'from_plan_id' => $change->to_plan_id,
                'to_plan_id' => $restored ? $change->from_plan_id : $change->to_plan_id,
                'reason' => self::AUDIT_REASON,
                'refusal' => $refusal,
                'plan_restored' => $restored,
                'plan_stock_exceeded_by' => $exceededBy,
                'proration_invoice_id' => (string) $invoice->getKey(),
                'plan_change_id' => (string) $change->getKey(),
                'returned_to_wallet_minor' => $credited,
            ],
        );

        Log::warning('A paid plan change could no longer be delivered when its payment was captured; the payment was returned to the wallet and the plan put back.', [
            'invoice_id' => (string) $invoice->getKey(),
            'subscription_id' => (string) $locked->getKey(),
            'plan_change_id' => (string) $change->getKey(),
            'refusal' => $refusal,
            'plan_restored' => $restored,
            'plan_stock_exceeded_by' => $exceededBy,
            'returned_to_wallet_minor' => $credited,
        ]);

        $this->tellTheCustomerOnceCommitted($locked, $change, $invoice, $credited);

        return $credited;
    }

    /**
     * After the commit, as an invoice is announced (IssueInvoice): a message
     * about a return that rolled back would tell the customer of money that
     * never moved.
     */
    private function tellTheCustomerOnceCommitted(Subscription $subscription, PlanChange $change, Invoice $invoice, int $credited): void
    {
        $service = Service::query()->where('subscription_id', $subscription->getKey())->first();
        $label = $service?->label;
        $name = $service === null
            ? ['en' => 'your service', 'ar' => 'خدمتك']
            : (is_string($label) && $label !== '' ? $label : (string) $service->getKey());
        $amount = Money::ofMinor($credited, (string) $invoice->currency)->format();

        DB::afterCommit(function () use ($subscription, $change, $name, $amount): void {
            $this->notify->execute(
                customerId: (string) $subscription->customer_id,
                type: NotificationType::PlanChangeReturned,
                idempotencyKey: 'plan-change-returned:'.$change->getKey(),
                subject: $subscription,
                data: ['service' => $name, 'amount' => $amount],
                link: '/subscriptions',
            );
        });
    }

    /**
     * Back to the plan the change left, when the subscription is still where
     * the change put it and nothing was changed after it.
     */
    private function restoreThePlan(Subscription $locked, PlanChange $change): bool
    {
        if ($change->from_plan_id === null
            || $change->from_recurring_amount_minor === null
            || $locked->status->isTerminal()
            || $locked->plan_id !== $change->to_plan_id) {
            return false;
        }

        $later = PlanChange::query()
            ->where('subscription_id', $change->subscription_id)
            ->where(static fn ($query) => $query
                ->where('changed_at', '>', $change->changed_at)
                ->orWhere(static fn ($same) => $same
                    ->where('changed_at', $change->changed_at)
                    ->where('id', '>', $change->id)))
            ->exists();

        if ($later) {
            return false;
        }

        $this->capacity->lock([(string) $change->from_plan_id]);

        $locked->plan_id = $change->from_plan_id;
        $locked->recurring_amount_minor = $change->from_recurring_amount_minor;
        $locked->save();

        return true;
    }

    /**
     * By how many units the plan the subscription went back to is now over
     * its stock_limit (zero when it is not, or has none), read under the
     * plan's lock (restoreThePlan() took it) and after the move.
     *
     * A paid upgrade stops counting its unit against the plan it left
     * (PlanCapacity::claimed()), so that plan can have sold the unit between
     * the payment and this settlement. The return is not refused for it: the
     * customer held that plan, and the machine or account never left its
     * shape. So the stock may be exceeded, by at most this change's units -
     * recorded here, for an operator, rather than left silent (F-06's
     * residue, the re-audit after round six).
     */
    private function stockExceededBy(string $planId): int
    {
        /** @var Plan|null $plan */
        $plan = Plan::query()->find($planId);

        if ($plan === null || $plan->stock_limit === null) {
            return 0;
        }

        return max(0, $this->capacity->claimed($planId) - (int) $plan->stock_limit);
    }
}
