<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Queries;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * The upgrade a subscription sits on without having paid for it.
 *
 * ApplyPlanChange moves the plan and the recurring amount the moment an
 * upgrade is confirmed, and delivers it when its invoice is paid. Until then
 * the move is provisional: the plan the subscription has paid for is the one
 * the change came from, at the amount the change recorded. Three places need
 * that answer and must agree on it - the renewal (which lapses or bills it),
 * the credit a later change prices (which must not return money for a plan
 * nobody paid for), and the voided-invoice restore.
 *
 * Only the latest change is read. While a plan-change invoice is open no
 * further change can be made, so an unpaid one is always the latest.
 */
final readonly class UnpaidUpgrade
{
    /**
     * The latest change, when the subscription is still on the plan it moved
     * to and that change's invoice was never paid. Null otherwise.
     */
    public function onto(Subscription $subscription): ?PlanChange
    {
        /** @var PlanChange|null $latest */
        $latest = PlanChange::query()
            ->where('subscription_id', $subscription->getKey())
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null
            || $latest->proration_invoice_id === null
            || $latest->from_recurring_amount_minor === null
            || $latest->to_plan_id !== $subscription->plan_id) {
            return null;
        }

        /*
         * Paid is paid. Refunded was paid, delivered, and then handed back by
         * an operator: that is the refund's decision to make, and the period's
         * ceiling already keeps the money it returned from being credited
         * again. Anything else - open, draft, void, uncollectible - never paid.
         */
        return in_array($this->statusOf($latest), [InvoiceStatus::Paid, InvoiceStatus::Refunded], true) ? null : $latest;
    }

    /**
     * The upgrade's invoice, when it is open and nothing has been paid on it -
     * the one a renewal lapses by voiding.
     */
    public function openInvoiceOf(Subscription $subscription): ?Invoice
    {
        $change = $this->onto($subscription);

        if ($change === null) {
            return null;
        }

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()->find($change->proration_invoice_id);

        return $invoice !== null && $invoice->status === InvoiceStatus::Open ? $invoice : null;
    }

    /**
     * The recurring amount the subscription has paid for: the pre-change
     * amount while an upgrade is unpaid, its own amount otherwise.
     */
    public function recurringPaidFor(Subscription $subscription): Money
    {
        $change = $this->onto($subscription);

        return $change === null
            ? $subscription->recurringAmount()
            : Money::ofMinor((int) $change->from_recurring_amount_minor, $subscription->currency);
    }

    private function statusOf(PlanChange $change): ?InvoiceStatus
    {
        $status = Invoice::query()->whereKey($change->proration_invoice_id)->value('status');

        return $status instanceof InvoiceStatus ? $status : InvoiceStatus::tryFrom((string) $status);
    }
}
