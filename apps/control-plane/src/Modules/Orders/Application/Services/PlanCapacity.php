<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;

/**
 * How much of a finite plan is already spoken for, and the claim on it.
 *
 * ---------------------------------------------------------------------------
 * Why a read is not a claim
 * ---------------------------------------------------------------------------
 *
 * `stock_limit` and `per_customer_limit` were enforced by counting rows and
 * comparing. Nothing was locked, so every checkout in flight read the same
 * remaining capacity and every one of them liked the answer: eight processes
 * racing for one unit sold five of it, and eight tabs of one customer against
 * a limit of one bought four.
 *
 * The comment on the old check said the authoritative claim happened later,
 * "when the paid order reserves capacity". No such code existed. That sentence
 * was the whole defence.
 *
 * The coupon path in the same checkout had it right all along — it takes
 * `SELECT ... FOR UPDATE` on the coupon row inside the transaction that writes
 * the order — and this is that shape applied to the plan.
 *
 * ---------------------------------------------------------------------------
 * What holds capacity, and what gives it back
 * ---------------------------------------------------------------------------
 *
 * An order awaiting payment still holds its unit, because otherwise ten
 * customers can each reserve the last machine while sitting on the card form.
 * A cancelled order releases it, and so does a terminated one: the service it
 * bought has ended.
 *
 * A refunded order does NOT release it by being refunded. This sentence used
 * to say it did, and while nothing could write `refunded` the claim was
 * dormant; F-19 made the status reachable and the sentence became behaviour —
 * measured: the order refunded, its service and subscription still active, the
 * plan's last unit counted free and sold to a second customer while the first
 * one's machine was still running. The product decision is that a refund
 * records the money and nothing else. So a refunded order holds its unit for
 * as long as any service it bought is still live, and gives it back when the
 * last one ends — which is read from the services, because `refunded` is the
 * order's last status and the ending happens on the service row.
 *
 * The release is arithmetic rather than a compensating write — the count
 * simply stops including those orders — so there is no second ledger to drift
 * out of step with the orders and services themselves. The same rule governs
 * an unredeemed coupon hold, and PlaceOrder asks stillHolding() for it rather
 * than keeping a second copy of the list.
 *
 * A plan change moves a held unit rather than taking a new one: the unit is
 * counted against the plan its subscription is on now (see claimed()), and
 * ApplyPlanChange asks shortfall() of the plan it moves onto, after lock(),
 * inside the transaction that moves it - the same claim an order makes.
 *
 * Nothing here is a counter. The number is derived from durable order rows
 * every time it is asked for, which is what makes a cancellation release
 * capacity with no write at all, and what makes this safe to re-ask under a
 * lock.
 */
final readonly class PlanCapacity
{
    /**
     * Orders that no longer hold what they asked for, whatever else is true.
     *
     * Not `refunded`: see the class docblock, and stillHolding().
     *
     * @var list<string>
     */
    /** shortfall(): every unit the plan has is held. */
    public const string OUT_OF_STOCK = 'out_of_stock';

    /** shortfall(): the customer already holds as many as they may. */
    public const string PER_CUSTOMER_LIMIT = 'per_customer_limit';

    private const array RELEASED = [
        OrderStatus::Cancelled->value,
        OrderStatus::Terminated->value,
    ];

    /**
     * Narrows a query over `orders` to the orders still holding what they
     * asked for: not released, and — for a refunded order — with a service
     * that has not ended.
     *
     * @return Builder the same query, narrowed
     */
    public static function stillHolding(Builder $query): Builder
    {
        return $query
            ->whereNotIn('orders.status', self::RELEASED)
            ->where(static fn (Builder $held): Builder => $held
                ->where('orders.status', '!=', OrderStatus::Refunded->value)
                ->orWhereExists(static fn (Builder $live): Builder => $live
                    ->selectRaw('1')
                    ->from('services')
                    ->whereColumn('services.order_id', 'orders.id')
                    ->where('services.status', '!=', ServiceStatus::Terminated->value)));
    }

    /**
     * How many units of this plan are already claimed, optionally by one
     * customer.
     *
     * The read-only form, used by the quote and by the checkout's early
     * refusal. It is a courtesy rather than a guarantee: it tells a customer
     * the plan is gone before they fill in a card form, and it is not what
     * stops an oversell.
     *
     * A unit is counted against the plan its subscription is on NOW, which is
     * the order line's plan until a plan change moves it. Counting only the
     * order line's plan had a plan change take a unit it never counted and
     * give back none: the plan moved onto kept selling a unit a subscription
     * already held, and the plan left behind stayed full (F-01 x F-06). The
     * line reaches its subscription through the service it was built into
     * (`services.order_item_id`); a line with no service yet - an order
     * awaiting payment - counts against the plan it was ordered on.
     */
    public function claimed(string $planId, ?Customer $customer = null): int
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('services as held_by', 'held_by.order_item_id', '=', 'order_items.id')
            ->leftJoin('subscriptions as held_on', 'held_on.id', '=', 'held_by.subscription_id')
            ->whereRaw('coalesce(held_on.plan_id, order_items.plan_id) = ?', [$planId])
            ->when($customer !== null, fn ($query) => $query->where('orders.customer_id', $customer->getKey()));

        return (int) self::stillHolding($query)->sum('order_items.quantity');
    }

    /**
     * Which limit taking `$quantity` more units of the plan would break, if
     * any: self::OUT_OF_STOCK, self::PER_CUSTOMER_LIMIT, or null.
     *
     * Read-only on its own. It is a claim only when asked after lock(), inside
     * the transaction that writes whatever consumes the unit - as claim() does
     * for an order, and ApplyPlanChange does for a plan change.
     */
    public function shortfall(Plan $plan, int $quantity, Customer $customer): ?string
    {
        $planId = (string) $plan->getKey();

        if ($plan->stock_limit !== null
            && $this->claimed($planId) + $quantity > $plan->stock_limit) {
            return self::OUT_OF_STOCK;
        }

        if ($plan->per_customer_limit !== null
            && $this->claimed($planId, $customer) + $quantity > $plan->per_customer_limit) {
            return self::PER_CUSTOMER_LIMIT;
        }

        return null;
    }

    /**
     * Take `SELECT ... FOR UPDATE` on each plan row, one statement each, in
     * sorted id order.
     *
     * Every writer that consumes a plan unit locks through here, so a checkout
     * and a plan change onto the same plan queue behind each other, and two
     * writers naming the same plans in opposite orders cannot deadlock.
     *
     * @param  list<string>  $planIds
     */
    public function lock(array $planIds): void
    {
        $planIds = array_values(array_unique($planIds));
        sort($planIds);

        foreach ($planIds as $planId) {
            DB::table('plans')->where('id', $planId)->lockForUpdate()->first();
        }
    }

    /**
     * Take the capacity, or refuse.
     *
     * Must be called inside the transaction that writes the order, and before
     * the items are inserted: the lock is only worth anything while it is
     * still held when the row that consumes the capacity is written.
     *
     * Plans are locked in a stable order — sorted by id, one statement each —
     * so two baskets naming the same two plans in opposite orders queue behind
     * each other instead of deadlocking. Sorting in PHP rather than leaning on
     * `ORDER BY ... FOR UPDATE` is deliberate: the guarantee then belongs to
     * this loop rather than to a query planner's choice of plan.
     *
     * The counts are re-read after every lock is held, never before. A count
     * taken before the lock is the same stale read this class exists to
     * replace.
     *
     * @param  array<string, int>  $wanted  plan id => quantity this order is about to insert
     *
     * @throws CheckoutRejectedException
     */
    public function claim(Customer $customer, array $wanted): void
    {
        $planIds = array_map(strval(...), array_keys($wanted));
        sort($planIds);

        $this->lock($planIds);

        foreach ($planIds as $planId) {
            /** @var Plan|null $plan */
            $plan = Plan::query()->find($planId);

            if ($plan === null) {
                continue;
            }

            $refused = $this->shortfall($plan, $wanted[$planId], $customer);

            if ($refused === self::OUT_OF_STOCK) {
                throw CheckoutRejectedException::becausePlanIsOutOfStock($planId);
            }

            if ($refused === self::PER_CUSTOMER_LIMIT) {
                throw CheckoutRejectedException::becausePerCustomerLimitReached(
                    $planId,
                    (int) $plan->per_customer_limit,
                );
            }
        }
    }
}
