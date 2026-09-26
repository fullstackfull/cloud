<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\Queries;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Application\Actions\ChangeSubscriptionPlan;
use Lynomia\Modules\Subscriptions\Application\Actions\QuotePlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * How much of the money this subscription's current period brought in is
 * still the customer's to have back.
 *
 * ---------------------------------------------------------------------------
 * Why a downgrade credit needs a ceiling
 * ---------------------------------------------------------------------------
 *
 * A downgrade returns the unused remainder of the plan being left as wallet
 * balance, and the remainder is priced from the subscription's recurring
 * amount. That amount says what the plan costs, not what was paid for it.
 * The re-audit (F-01) moved small -> large -> small three times, paid nothing,
 * and was credited 162.000 KWD: every downgrade returned the remainder of a
 * large plan whose upgrade invoice had never been paid. The same shape is
 * reachable wherever the period's money did not arrive in full - an unpaid
 * renewal, a voided or refunded invoice, a coupon.
 *
 * So the credit is capped at this figure: what the period collected, less
 * what plan changes in the period have already returned. Balance can then
 * never come out of a subscription faster than money went into it.
 *
 * ---------------------------------------------------------------------------
 * What is counted, exactly
 * ---------------------------------------------------------------------------
 *
 * Collected is the sum of `amount_paid_minor - amount_refunded_minor` over:
 *
 *  - every invoice issued against this subscription (`invoices.subscription_id`)
 *    that carries a line whose `period_start` falls inside the current period
 *    - the renewal that bought the period, and the proration invoices of
 *    changes made in it;
 *  - and, when no renewal line exists for the current period (the period the
 *    order bought), the order lines this subscription's services were built
 *    from, less their one-off setup fee, and never more than the order's own
 *    invoices collected.
 *
 * Returned is the sum of `wallet_credit_minor` over the plan changes recorded
 * for this subscription since the period began.
 *
 * The figure is money, not time: it is not prorated. When every invoice was
 * paid it sits well above any remainder a downgrade can compute, so it never
 * bites on a customer who paid. When something was not paid it bounds the
 * credit by what was, and the customer can at most be handed back money they
 * handed over this period. Tax is inside the paid amounts and outside the
 * remainder, which makes the ceiling looser, never tighter.
 *
 * Read by {@see QuotePlanChange} and {@see ChangeSubscriptionPlan} alike, so
 * the quote and the credit agree; {@see ApplyPlanChange} writes the rows that
 * make the returned half.
 */
final readonly class MoneyCollectedForThePeriod
{
    public function stillReturnable(Subscription $subscription): Money
    {
        $collected = $this->collectedOnTheSubscription($subscription);

        if (! $this->periodWasRenewed($subscription)) {
            $collected += $this->collectedByTheOrder($subscription);
        }

        $returned = (int) DB::table('subscription_plan_changes')
            ->where('subscription_id', $subscription->getKey())
            ->where('changed_at', '>=', $subscription->current_period_start)
            ->sum('wallet_credit_minor');

        return Money::ofMinor(max(0, $collected - $returned), $subscription->currency);
    }

    /**
     * The credit half of a proration (a positive amount: the remainder of the
     * plan being left), lowered so the net comes out at no more
     * than what is still returnable.
     *
     * Only ever lowers, and only when the change is a downgrade: an upgrade's
     * credit is already below its charge and is left exactly as priced, so an
     * upgrade invoice carries the same lines it always did.
     */
    public function ceilCredit(Money $credit, Money $charge, Subscription $subscription): Money
    {
        $ceiling = $charge->plus($this->stillReturnable($subscription));

        return $credit->isGreaterThan($ceiling) ? $ceiling : $credit;
    }

    private function collectedOnTheSubscription(Subscription $subscription): int
    {
        return (int) DB::table('invoices')
            ->where('subscription_id', $subscription->getKey())
            ->where('currency', $subscription->currency)
            ->whereExists(fn (Builder $lines): Builder => $lines
                ->selectRaw('1')
                ->from('invoice_items')
                ->whereColumn('invoice_items.invoice_id', 'invoices.id')
                ->where('invoice_items.period_start', '>=', $subscription->current_period_start)
                ->where('invoice_items.period_start', '<', $subscription->current_period_end))
            ->sum(DB::raw('amount_paid_minor - amount_refunded_minor'));
    }

    private function periodWasRenewed(Subscription $subscription): bool
    {
        return DB::table('invoice_items')
            ->where('subscription_id', $subscription->getKey())
            ->where('kind', InvoiceItemKind::Plan->value)
            ->where('period_start', '>=', $subscription->current_period_start)
            ->where('period_start', '<', $subscription->current_period_end)
            ->exists();
    }

    private function collectedByTheOrder(Subscription $subscription): int
    {
        /** @var list<object{order_id: string, line_minor: int|string}> $lines */
        $lines = DB::table('services')
            ->join('order_items', 'order_items.id', '=', 'services.order_item_id')
            ->where('services.subscription_id', $subscription->getKey())
            ->groupBy('order_items.order_id')
            ->selectRaw('order_items.order_id as order_id, sum(order_items.total_minor - order_items.unit_setup_minor) as line_minor')
            ->get()
            ->all();

        $collected = 0;

        foreach ($lines as $line) {
            $paid = (int) DB::table('invoices')
                ->where('order_id', $line->order_id)
                ->where('currency', $subscription->currency)
                ->sum(DB::raw('amount_paid_minor - amount_refunded_minor'));

            $collected += max(0, min((int) $line->line_minor, $paid));
        }

        return $collected;
    }
}
