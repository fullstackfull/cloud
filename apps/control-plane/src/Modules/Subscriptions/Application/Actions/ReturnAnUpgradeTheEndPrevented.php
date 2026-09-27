<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * Returns to the wallet an upgrade paid for and never delivered because the
 * subscription ended.
 *
 * An upgrade is delivered by the resize its settlement queues
 * ({@see ResizeOnPlanChangeSettlement}, keyed `plan-change:<subscription>:<plan>:invoice:<id>`
 * by QueuePlanChangeAtProvider). A settlement heard after the subscription
 * ended queues nothing (O-1) - the machine is being switched off - and the
 * money used to be kept, "an operator's to refund", with a log line. That is
 * money for nothing, and it is not kept (OA-3, the coordinator's ruling on
 * round four's re-audit): what the invoice still holds
 * (WhatAnInvoiceStillHolds) goes back to the wallet, recorded against the
 * invoice, through ReturnWhatAnInvoiceStillHolds.
 *
 * Asked from both sides of the race, and idempotent between them (the return
 * reads what the invoice holds under its lock, so a second call credits
 * nothing): the wind-up of the ended subscription
 * ({@see WindUpAnEndedSubscription}), for an upgrade paid before the end whose
 * settlement has not been heard; and the settlement heard after the end, for
 * one the wind-up did not see.
 *
 * Returned only when all of these hold, and otherwise left alone:
 *  - the subscription has ended;
 *  - the invoice is paid and carries a proration line (an upgrade's, not a
 *    renewal's);
 *  - no resize or package change was queued for it - one was, and the upgrade
 *    was delivered, when its settlement was heard before the end;
 *  - it is the subscription's newest plan change, when the change is
 *    recorded: an upgrade a later change superseded was settled by that
 *    change (its credit is drawn on this invoice), not left undelivered.
 */
final readonly class ReturnAnUpgradeTheEndPrevented
{
    public function __construct(
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
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

        if ($subscription === null || ! $subscription->status->isTerminal()) {
            return 0;
        }

        $isAnUpgrade = InvoiceItem::query()
            ->where('invoice_id', $invoice->getKey())
            ->where('kind', InvoiceItemKind::Proration->value)
            ->exists();

        if (! $isAnUpgrade || $this->wasDelivered($subscription, $invoice) || $this->wasSuperseded($invoice)) {
            return 0;
        }

        $credited = $this->returnWhatItHolds->toTheWallet(
            $invoice,
            'upgrade-not-delivered',
            sprintf('Payment for invoice %s returned: the upgrade was not delivered before the subscription ended', $invoice->number),
            ['subscription_id' => (string) $subscription->getKey()],
        );

        if ($credited > 0) {
            Log::info('An upgrade paid for and never delivered, because its subscription ended, was returned to the wallet.', [
                'invoice_id' => (string) $invoice->getKey(),
                'subscription_id' => (string) $subscription->getKey(),
                'credited_minor' => $credited,
            ]);
        }

        return $credited;
    }

    /**
     * Whether the settlement queued the resize (or the package change) this
     * invoice paid for, under the key QueuePlanChangeAtProvider gives it.
     */
    private function wasDelivered(Subscription $subscription, Invoice $invoice): bool
    {
        return ProvisioningJob::query()
            ->where('idempotency_key', 'like', sprintf('plan-change:%s:%%:invoice:%s', $subscription->getKey(), $invoice->getKey()))
            ->exists();
    }

    private function wasSuperseded(Invoice $invoice): bool
    {
        /** @var PlanChange|null $change */
        $change = PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->first();

        if ($change === null) {
            return false;
        }

        return PlanChange::query()
            ->where('subscription_id', $change->subscription_id)
            ->where(static fn ($later) => $later
                ->where('changed_at', '>', $change->changed_at)
                ->orWhere(static fn ($same) => $same
                    ->where('changed_at', $change->changed_at)
                    ->where('id', '>', $change->id)))
            ->exists();
    }
}
