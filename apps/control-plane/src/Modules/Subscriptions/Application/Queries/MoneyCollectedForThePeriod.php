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
 *    order bought), this subscription's share of the order's pool (see
 *    collectedByTheOrder(): the pool is shared by every subscription the
 *    order bought, and what siblings already drew is taken out) - the recurring part of the order lines this subscription's
 *    services were built from - the line total less its setup fee, and never
 *    more than `unit_recurring_minor x quantity`, so no setup money is counted
 *    whether a line's setup was charged once (PricingLine::gross() charges it
 *    once per line) or once per unit - and never more than the order's own
 *    invoices collected.
 *
 * Returned is the sum of `wallet_credit_minor` over the plan changes recorded
 * for this subscription inside the current period.
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
        $from = $subscription->current_period_start;
        $until = $subscription->current_period_end;
        $id = (string) $subscription->getKey();

        $collected = $this->collectedOnTheSubscription($id, $subscription->currency, $from, $until);

        if (! $this->periodWasRenewed($subscription)) {
            $collected += $this->collectedByTheOrder($subscription);
        }

        return Money::ofMinor(max(0, $collected - $this->returned($id, $from, $until)), $subscription->currency);
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

    /**
     * Take `SELECT ... FOR UPDATE` on every order this subscription's services
     * were bought on, in sorted id order.
     *
     * The money an order collected is one pool, shared by every subscription
     * that order bought. Two of them downgrading at once would each read the
     * pool before the other's credit was written and both draw on it in full.
     * ApplyPlanChange calls this under the subscription's lock and before it
     * quotes, so sibling changes queue on the order row and the second reads
     * what the first returned.
     */
    public function lockTheOrdersBehind(Subscription $subscription): void
    {
        $orderIds = DB::table('services')
            ->join('order_items', 'order_items.id', '=', 'services.order_item_id')
            ->where('services.subscription_id', $subscription->getKey())
            ->distinct()
            ->pluck('order_items.order_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->sort()
            ->values()
            ->all();

        foreach ($orderIds as $orderId) {
            DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        }
    }

    private function collectedOnTheSubscription(string $subscriptionId, string $currency, mixed $from, mixed $until): int
    {
        return (int) DB::table('invoices')
            ->where('subscription_id', $subscriptionId)
            ->where('currency', $currency)
            ->whereExists(fn (Builder $lines): Builder => $lines
                ->selectRaw('1')
                ->from('invoice_items')
                ->whereColumn('invoice_items.invoice_id', 'invoices.id')
                ->where('invoice_items.period_start', '>=', $from)
                ->where('invoice_items.period_start', '<', $until))
            ->sum(DB::raw('amount_paid_minor - amount_refunded_minor'));
    }

    /**
     * What plan changes of one subscription gave back as wallet credit inside
     * the window.
     */
    private function returned(string $subscriptionId, mixed $from, mixed $until): int
    {
        return (int) DB::table('subscription_plan_changes')
            ->where('subscription_id', $subscriptionId)
            ->where('changed_at', '>=', $from)
            ->where('changed_at', '<', $until)
            ->sum('wallet_credit_minor');
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

    /**
     * This subscription's share of what its order collected.
     *
     * Its own lines' recurring money, never more than what is left of the
     * order's pool - the order's invoices, paid less refunded - once every
     * sibling subscription bought on the same order has taken what it drew
     * from it. A sibling's draw is what its plan changes returned in the
     * period beyond what its own invoices collected: credit funded by its own
     * proration invoices is not the order's money. Siblings share this
     * subscription's period, because one checkout bills every line on one
     * cycle, so the same window measures them.
     *
     * Without the siblings' draw, each subscription saw the whole pool: an
     * order paid 180.000 and refunded 150.000 let two 90.000 subscriptions
     * each take 27.000 back, 54.000 out of 30.000 collected.
     */
    private function collectedByTheOrder(Subscription $subscription): int
    {
        $from = $subscription->current_period_start;
        $until = $subscription->current_period_end;

        /** @var list<object{order_id: string, line_minor: int|string}> $lines */
        $lines = DB::table('services')
            ->join('order_items', 'order_items.id', '=', 'services.order_item_id')
            ->where('services.subscription_id', $subscription->getKey())
            ->groupBy('order_items.order_id')
            ->selectRaw('order_items.order_id as order_id, sum(least(order_items.total_minor - order_items.unit_setup_minor, order_items.unit_recurring_minor * order_items.quantity)) as line_minor')
            ->get()
            ->all();

        $collected = 0;

        foreach ($lines as $line) {
            $paid = (int) DB::table('invoices')
                ->where('order_id', $line->order_id)
                ->where('currency', $subscription->currency)
                ->sum(DB::raw('amount_paid_minor - amount_refunded_minor'));

            $siblings = DB::table('services')
                ->join('order_items', 'order_items.id', '=', 'services.order_item_id')
                ->where('order_items.order_id', $line->order_id)
                ->whereNotNull('services.subscription_id')
                ->where('services.subscription_id', '!=', $subscription->getKey())
                ->distinct()
                ->pluck('services.subscription_id');

            $drawn = 0;

            foreach ($siblings as $sibling) {
                $sibling = (string) $sibling;
                $drawn += max(0, $this->returned($sibling, $from, $until)
                    - $this->collectedOnTheSubscription($sibling, $subscription->currency, $from, $until));
            }

            $collected += max(0, min((int) $line->line_minor, $paid - $drawn));
        }

        return $collected;
    }
}
