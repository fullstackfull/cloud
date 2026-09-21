<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Services;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;

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
 * Unchanged, and deliberately: an order awaiting payment still holds its unit,
 * because otherwise ten customers can each reserve the last machine while
 * sitting on the card form. Cancelled, refunded and terminated orders release
 * theirs. That release is arithmetic rather than a compensating write — the
 * count simply stops including them — so there is no second ledger to drift
 * out of step with the orders themselves.
 *
 * Nothing here is a counter. The number is derived from durable order rows
 * every time it is asked for, which is what makes a cancellation release
 * capacity with no write at all, and what makes this safe to re-ask under a
 * lock.
 */
final readonly class PlanCapacity
{
    /**
     * Orders that no longer hold what they asked for.
     *
     * @var list<string>
     */
    private const array RELEASED = [
        OrderStatus::Cancelled->value,
        OrderStatus::Refunded->value,
        OrderStatus::Terminated->value,
    ];

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
        return (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.plan_id', $planId)
            ->whereNotIn('orders.status', self::RELEASED)
            ->when($customer !== null, fn ($query) => $query->where('orders.customer_id', $customer->getKey()))
            ->sum('order_items.quantity');
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
