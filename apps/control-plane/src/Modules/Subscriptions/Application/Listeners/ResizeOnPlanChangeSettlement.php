<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Application\Actions\QueuePlanChangeAtProvider;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAnUpgradeTheEndPrevented;
use Lynomia\Modules\Subscriptions\Application\Actions\ReturnAPlanChangeNoLongerDeliverable;
use Lynomia\Modules\Subscriptions\Application\Queries\PlanChangeDelivery;
use Lynomia\Modules\Subscriptions\Domain\ValueObjects\PlanResources;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * The upgrade was paid for, so now the customer may have it.
 *
 * ---------------------------------------------------------------------------
 * Why the resize is not queued when the change is requested
 * ---------------------------------------------------------------------------
 *
 * An upgrade is a purchase. The platform's rule everywhere else is that
 * nothing is handed over against an open invoice — an order provisions on
 * settlement, never on placement — and a plan change had been the one place
 * that broke it: the machine grew the moment the customer pressed confirm and
 * the money was a separate question afterwards. So
 * {@see ApplyPlanChange}
 * now issues the proration invoice and queues nothing, and the resize waits
 * here for the same event that fulfils an order.
 *
 * A downgrade and a like-for-like change do not come through here at all.
 * Neither owes anything, so neither produces an invoice, and both are queued
 * at the moment they are requested.
 *
 * ---------------------------------------------------------------------------
 * Telling a proration invoice from a renewal invoice
 * ---------------------------------------------------------------------------
 *
 * Both carry a subscription id, and a renewal being paid must not resize
 * anything. The line kinds are what separate them: a renewal bills
 * {@see InvoiceItemKind::Plan}, and only a plan change writes a
 * {@see InvoiceItemKind::Proration} line. That is read off the invoice rather
 * than inferred from the subscription's state, because by the time the money
 * arrives the subscription looks identical either way.
 *
 * ---------------------------------------------------------------------------
 * Which plan gets built
 * ---------------------------------------------------------------------------
 *
 * The one this invoice paid for, read from the {@see PlanChange} row that
 * ApplyPlanChange wrote in the same transaction as the invoice: its target
 * plan and the shape the quote stated. Not the plan the subscription holds
 * when the money arrives. This used to read the subscription, on the theory
 * that "the current plan is the right answer anyway", and the re-audit paid a
 * 0.667 KWD invoice for small -> mid and was handed the 90.000 KWD large plan
 * a later, unpaid change had moved the subscription onto (F-01).
 *
 * A later change can still make this invoice's shape the wrong one to build.
 * If a change recorded after it has already been settled - it owed nothing
 * and queued its own resize, or its own invoice was paid - that change
 * decides the machine, and this one builds nothing. If every later change is
 * still waiting on its invoice, this one is the newest thing paid for, and it
 * is built.
 *
 * A proration invoice with no recorded change can only be one issued before
 * the record existed: every change since writes its row in the transaction
 * that issues its invoice. The old rule allowed further changes while such an
 * invoice was open, so the plan the subscription holds now is not
 * necessarily what it bought (small -> mid, then mid -> large, then only the
 * first invoice paid). What it bought is read from the plan_changed audit
 * entry the old code wrote beside it, which names the invoice and the plan it
 * moved to. When a later proration invoice has already been paid, that one
 * decides the machine and this builds nothing. When the audit entry cannot be
 * found, the subscription's plan is built only if no later proration invoice
 * exists - otherwise nothing is built, and the log says why, for an operator.
 *
 * ---------------------------------------------------------------------------
 * A change that stopped being deliverable before its capture
 * ---------------------------------------------------------------------------
 *
 * The payment asks whether the change can still be delivered when it is
 * opened (PlanChangeDelivery::refusalForTheInvoice()); a card payment is
 * captured afterwards, at the provider. So the settlement asks again
 * (PlanChangeDelivery::refusalForTheChange()), and a change it refuses is not
 * delivered as nothing and kept: what the invoice holds goes back to the
 * wallet, the subscription goes back to the plan it came from, and the
 * change's record, the audit trail and the log say so
 * ({@see ReturnAPlanChangeNoLongerDeliverable}). It used to be recorded
 * delivered with nothing queued and the money kept, a log warning the only
 * trace. What this cannot see is a change that becomes undeliverable after
 * the settlement: a resize the node refuses at the build stops in review with
 * the money held for an operator, and the customer is told the change is
 * waiting (NotifyOnProvisioningOutcome).
 *
 * Queued on payments beside the other settlement work, and idempotent twice
 * over: the provisioning job is keyed on the invoice that paid for it, so a
 * redelivered settlement finds the job the first delivery made instead of
 * resizing the machine again.
 */
final class ResizeOnPlanChangeSettlement implements ShouldQueue
{
    public string $queue = 'payments';

    public int $tries = 5;

    /**
     * A wait before every retry. Five tries with no ladder is not five
     * attempts; it is one attempt five times inside the same outage, on the
     * queue that moves money (F-08).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 300];
    }

    public function __construct(
        private readonly QueuePlanChangeAtProvider $queueAtProvider,
        private readonly ReturnAnUpgradeTheEndPrevented $returnAnUpgrade,
        private readonly PlanChangeDelivery $delivery,
        private readonly ReturnAPlanChangeNoLongerDeliverable $returnIt,
    ) {}

    public function handle(InvoicePaid $event): void
    {
        if ($event->subscriptionId === null) {
            return;
        }

        $carriesProration = InvoiceItem::query()
            ->where('invoice_id', $event->invoiceId)
            ->where('kind', InvoiceItemKind::Proration->value)
            ->exists();

        if (! $carriesProration) {
            // A renewal, or any other invoice that happens to name a
            // subscription. Nothing about the product changed.
            return;
        }

        $change = PlanChange::query()->where('proration_invoice_id', $event->invoiceId)->first();

        if ($change === null) {
            $this->buildForAnInvoiceIssuedBeforeTheRecord($event);

            return;
        }

        /*
         * Decided under the subscription's lock, the lock the ending takes
         * (WindUpAnEndedSubscription, through `$end`), so the two serialise:
         * either this runs while the subscription is live and records the
         * change delivered - and the wind-up keeps the money - or it runs
         * after the end and returns it (hasEnded()). Read without the lock,
         * a settlement and an ending could each see the other not yet done.
         * The subscription, then (on the return) the paid invoice and the
         * wallet: the order WhatAnInvoiceStillHolds declares for a paid
         * invoice taken after a subscription.
         */
        DB::transaction(function () use ($change, $event): void {
            /** @var Subscription|null $subscription */
            $subscription = Subscription::query()->lockForUpdate()->find($change->subscription_id);

            if ($subscription === null || $change->to_plan_id === null) {
                Log::warning('A paid proration invoice names a subscription or a plan that no longer exists.', [
                    'invoice_id' => $event->invoiceId,
                    'subscription_id' => $change->subscription_id,
                ]);

                return;
            }

            if ($this->hasEnded($subscription, $event)) {
                return;
            }

            /** @var PlanChange $recorded */
            $recorded = PlanChange::query()->findOrFail($change->getKey());

            if ($recorded->returned_at !== null) {
                // Returned by an earlier delivery of this settlement.
                return;
            }

            $superseded = $this->delivery->aLaterChangeWasSettled($recorded);

            /*
             * Still deliverable? Asked when the payment was opened, and asked
             * again here, because a card payment is captured later and an
             * operator can withdraw the package, fill the node or remove the
             * machine in between. A change that can no longer be delivered is
             * returned - the money to the wallet against the invoice, the
             * subscription to the plan it came from - rather than recorded
             * delivered with nothing queued and the money kept
             * (ReturnAPlanChangeNoLongerDeliverable, which states the rule).
             * Not asked of a change already recorded delivered (a redelivered
             * settlement), nor of one a later settled change superseded: that
             * change decided the machine, and this one builds nothing either
             * way.
             */
            $refusal = $recorded->delivered_at !== null || $superseded
                ? null
                : $this->delivery->refusalForTheChange($recorded);

            if ($refusal !== null) {
                /** @var Invoice $invoice */
                $invoice = Invoice::query()->findOrFail($event->invoiceId);
                $this->returnIt->execute($subscription, $recorded, $invoice, $refusal);

                return;
            }

            /*
             * Delivered: the settlement was heard while the subscription was
             * live and the change could still be delivered, whatever it
             * queues below - a resize, nothing because a later change already
             * decided the machine, or nothing because there was nothing to
             * resize. What the end does not return
             * (ReturnAnUpgradeTheEndPrevented).
             */
            PlanChange::query()
                ->whereKey($change->getKey())
                ->whereNull('delivered_at')
                ->update(['delivered_at' => now()]);

            if ($superseded) {
                return;
            }

            $this->queueAtProvider->execute(
                subscription: $subscription,
                planId: (string) $change->to_plan_id,
                resources: PlanResources::fromArray($change->resources),
                delivers: 'invoice:'.$event->invoiceId,
            );
        });
    }

    /**
     * Nothing is resized onto a subscription that has ended (O-1).
     *
     * Its open invoices are withdrawn as it ends, and paying one is refused,
     * so this is reached by an upgrade paid in the moment before the end,
     * whose settlement is heard after it. The machine it would have grown is
     * being switched off or is already gone; resizing it is work at a
     * provider for a customer who will not have it. And the upgrade, paid for
     * and never delivered, is not kept: what its invoice still holds goes back
     * to the wallet, recorded against the invoice
     * ({@see ReturnAnUpgradeTheEndPrevented}, OA-3). The ended subscription's
     * wind-up asks the same for an upgrade it saw paid; between them it is
     * credited once. (This used to say the money was "kept, and an
     * operator's to refund", with only this log line to surface it.)
     */
    private function hasEnded(Subscription $subscription, InvoicePaid $event): bool
    {
        if (! $subscription->status->isTerminal()) {
            return false;
        }

        $returned = $this->returnAnUpgrade->execute($event->invoiceId);

        Log::warning('A plan change was paid for a subscription that has since ended; nothing was resized.', [
            'invoice_id' => $event->invoiceId,
            'subscription_id' => (string) $subscription->getKey(),
            'status' => $subscription->status->value,
            'returned_to_wallet_minor' => $returned,
        ]);

        return true;
    }

    /**
     * A proration invoice issued before `subscription_plan_changes` existed:
     * build what it bought, read from the old code's audit entry.
     */
    private function buildForAnInvoiceIssuedBeforeTheRecord(InvoicePaid $event): void
    {
        $subscription = Subscription::query()->find($event->subscriptionId);

        if ($subscription === null) {
            Log::warning('A paid proration invoice names a subscription that no longer exists.', [
                'invoice_id' => $event->invoiceId,
                'subscription_id' => $event->subscriptionId,
            ]);

            return;
        }

        if ($this->hasEnded($subscription, $event)) {
            return;
        }

        $later = Invoice::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('id', '>', $event->invoiceId)
            ->whereHas('items', static fn ($items) => $items->where('kind', InvoiceItemKind::Proration->value))
            ->get(['id', 'status']);

        if ($later->contains(static fn (Invoice $invoice): bool => $invoice->status === InvoiceStatus::Paid)) {
            Log::info('A paid proration invoice predates the plan-change record and a later one is already paid; the later one decides the machine.', [
                'invoice_id' => $event->invoiceId,
                'subscription_id' => $event->subscriptionId,
            ]);

            return;
        }

        $bought = AuditEntry::query()
            ->where('action', AuditAction::PlanChanged->value)
            ->where('context->proration_invoice_id', $event->invoiceId)
            ->latest('id')
            ->first();

        $planId = is_array($bought?->context) ? ($bought->context['to_plan_id'] ?? null) : null;
        $plan = is_string($planId) ? Plan::query()->find($planId) : null;

        if ($plan === null && $later->isNotEmpty()) {
            Log::warning('A paid proration invoice predates the plan-change record, what it bought cannot be established, and a later plan change followed it; nothing was built.', [
                'invoice_id' => $event->invoiceId,
                'subscription_id' => $event->subscriptionId,
            ]);

            return;
        }

        $plan ??= $subscription->plan()->first();

        if ($plan === null) {
            Log::warning('A paid proration invoice names a subscription with no plan.', [
                'invoice_id' => $event->invoiceId,
                'subscription_id' => $event->subscriptionId,
            ]);

            return;
        }

        $this->queueAtProvider->execute(
            subscription: $subscription,
            planId: (string) $plan->getKey(),
            resources: PlanResources::fromArray($plan->resources),
            delivers: 'invoice:'.$event->invoiceId,
        );
    }
}
