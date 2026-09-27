<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Actions;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Application\Queries\PlanChangeDelivery;
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
 *  - it was not delivered: its settlement was not heard while the
 *    subscription was live. The settlement records that it was
 *    (PlanChange::$delivered_at, under the subscription's lock), whatever it
 *    queued - a settlement that queued nothing because nothing needed
 *    resizing delivered the upgrade all the same. It used to be read off
 *    whether a resize job existed, and such an upgrade was returned at the
 *    end. For a change settled before `delivered_at` existed, and a
 *    proration invoice with no recorded change, a resize or package-change
 *    job keyed on the invoice still counts as delivered
 *    (PlanChangeDelivery::wasDelivered()). A change its settlement found
 *    could no longer be delivered was not delivered either, and was
 *    returned there (ReturnAPlanChangeNoLongerDeliverable): the invoice
 *    holds nothing more, and asked again here it credits nothing;
 *  - no later change was settled, when the change is recorded: an upgrade a
 *    later settled change superseded - a later change that owed nothing, or
 *    whose invoice was paid, and whose credit was drawn on this invoice -
 *    was settled by that change, not left undelivered. A later change still
 *    unpaid (voided as the subscription ends) supersedes nothing, and the
 *    upgrade is returned (X1).
 */
final readonly class ReturnAnUpgradeTheEndPrevented
{
    public function __construct(
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
        private PlanChangeDelivery $delivery,
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

        if (! $isAnUpgrade
            || $this->delivery->wasDelivered((string) $subscription->getKey(), (string) $invoice->getKey())
            || $this->wasSuperseded($invoice)) {
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
     * Superseded only by a later change that was settled - it owed nothing,
     * or its invoice was paid - the rule the settlement itself applies
     * (PlanChangeDelivery::aLaterChangeWasSettled()). Any later change used to
     * count, so an upgrade paid and not delivered, followed by a second
     * upgrade never paid (its invoice voided when the subscription ended),
     * was kept at the end: 27.000 KWD for nothing (X1, the re-audit after
     * round five). A later change that was never settled decided nothing and
     * drew nothing from this invoice.
     */
    private function wasSuperseded(Invoice $invoice): bool
    {
        /** @var PlanChange|null $change */
        $change = PlanChange::query()->where('proration_invoice_id', $invoice->getKey())->first();

        return $change !== null && $this->delivery->aLaterChangeWasSettled($change);
    }
}
