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
     */
    public function claimed(string $planId, ?Customer $customer = null): int
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.plan_id', $planId)
            ->when($customer !== null, fn ($query) => $query->where('orders.customer_id', $customer->getKey()));

        return (int) self::stillHolding($query)->sum('order_items.quantity');
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
        $planIds = array_keys($wanted);
        sort($planIds);

        foreach ($planIds as $planId) {
            DB::table('plans')->where('id', $planId)->lockForUpdate()->first();
        }

        foreach ($planIds as $planId) {
            /** @var Plan|null $plan */
            $plan = Plan::query()->find($planId);

            if ($plan === null) {
                continue;
            }

            $quantity = $wanted[$planId];

            if ($plan->stock_limit !== null
                && $this->claimed($planId) + $quantity > $plan->stock_limit) {
                throw CheckoutRejectedException::becausePlanIsOutOfStock($planId);
            }

            if ($plan->per_customer_limit !== null
                && $this->claimed($planId, $customer) + $quantity > $plan->per_customer_limit) {
                throw CheckoutRejectedException::becausePerCustomerLimitReached(
                    $planId,
                    $plan->per_customer_limit,
                );
            }
        }
    }
}
