<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricedOrder;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Catalog\Application\DTOs\CouponContext;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponCustomerLimitReachedException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponFullyRedeemedException;
use Lynomia\Modules\Catalog\Domain\Services\CouponValidator;
use Lynomia\Modules\Catalog\Domain\Services\TaxResolver;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\Events\OrderPlaced;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Services\OrderNumberAllocator;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Turns a basket into a priced, persisted order.
 *
 * Three properties this action exists to guarantee:
 *
 *  1. **Prices come from the catalogue, never from the client.** A checkout
 *     that trusts a submitted amount is a checkout where the customer sets
 *     their own price. Only plan ids and quantities cross the wire.
 *
 *  2. **A repeated submission returns the first order.** The client supplies an
 *     idempotency key and the database has a unique index on
 *     (customer_id, idempotency_key). A double-clicked buy button, a retried
 *     request over a flaky connection and a browser back-then-forward all
 *     converge on one order. The guard is the unique constraint, not a
 *     select-then-insert, because the latter is a race under exactly the
 *     conditions that produce the duplicate.
 *
 *  3. **The order is DRAFT when it is written and only then transitioned.**
 *     Writing it directly as PENDING_PAYMENT would bypass the state machine and
 *     leave no transition record of how it got there — which is precisely the
 *     information an operator needs when the order later stalls.
 *
 * Coupons are validated here but redeemed only when the order is paid: a basket
 * that is abandoned must not consume a single-use code. The counter alone
 * therefore does not bound the discount — it moves at payment, while the money
 * is given away here — so an unpaid order holds a use of the coupon in the same
 * way it already holds plan stock, and releases it when the order is cancelled.
 */
final readonly class PlaceOrder
{
    public function __construct(
        private PricingEngine $pricing,
        private TaxResolver $taxResolver,
        private CouponValidator $coupons,
        private OrderNumberAllocator $numbers,
        private TransitionOrder $transition,
    ) {}

    /**
     * @throws CheckoutRejectedException
     */
    public function execute(Customer $customer, CheckoutRequest $request, ?User $placedBy = null): Order
    {
        if ($request->lines === []) {
            throw CheckoutRejectedException::becauseBasketIsEmpty();
        }

        if (! $customer->canPurchase()) {
            throw CheckoutRejectedException::becauseAccountCannotPurchase($customer->status->value);
        }

        $existing = $this->findByIdempotencyKey($customer, $request->idempotencyKey);
        if ($existing !== null) {
            $this->assertSameRequest($existing, $request);

            return $existing;
        }

        $plans = $this->loadPlans($request);
        $pricingLines = $this->buildPricingLines($customer, $request, $plans);

        $taxRate = $this->taxResolver->forCustomer($customer, now());
        $coupon = $this->resolveCoupon($customer, $request, $plans, $pricingLines);

        $priced = $this->price($pricingLines, $taxRate, $coupon, $request->couponCode);

        return $this->persist($customer, $request, $plans, $pricingLines, $priced, $coupon, $placedBy);
    }

    /**
     * @param  list<PricingLine>  $pricingLines
     */
    private function price(array $pricingLines, TaxRate $taxRate, ?Coupon $coupon, ?string $couponCode): PricedOrder
    {
        if ($coupon === null) {
            return $this->pricing->price($pricingLines, $taxRate);
        }

        return $coupon->isPercentage()
            ? $this->pricing->price($pricingLines, $taxRate, percentageDiscount: $coupon->percentageRate(), couponCode: $couponCode)
            : $this->pricing->price($pricingLines, $taxRate, fixedDiscount: $coupon->fixedAmount(), couponCode: $couponCode);
    }

    /**
     * A key that comes back with a different basket is a conflict, not a replay.
     *
     * Returning the original order would be the worst of the three options: the
     * client believes its new basket was accepted, the customer has a
     * confirmation for something else, and nothing anywhere records that the
     * two disagreed. Placing a second order would be worse still - the key
     * exists precisely to stop that. So it is refused, loudly, and the client
     * can retry with a new key.
     *
     * An order written before the fingerprint column existed has none, and
     * keeps replaying exactly as it did before rather than starting to answer
     * 409 to clients that never changed.
     */
    private function assertSameRequest(Order $existing, CheckoutRequest $request): void
    {
        if ($existing->request_fingerprint === null) {
            return;
        }

        if (! hash_equals($existing->request_fingerprint, $request->fingerprint())) {
            throw CheckoutRejectedException::becauseIdempotencyKeyWasReused(
                (string) $request->idempotencyKey,
            );
        }
    }

    private function findByIdempotencyKey(Customer $customer, ?string $key): ?Order
    {
        if ($key === null || $key === '') {
            return null;
        }

        return Order::query()
            ->where('customer_id', $customer->getKey())
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * @return Collection<string, Plan>
     */
    private function loadPlans(CheckoutRequest $request): Collection
    {
        $ids = array_map(static fn ($line): string => $line->planId, $request->lines);

        /** @var Collection<string, Plan> $plans */
        $plans = Plan::query()
            ->with(['prices', 'product'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $plan = $plans->get($id);

            /*
             * The same predicate the catalogue uses to decide what is on sale —
             * Plan::scopePurchasable(), applied to the plan and inherited from
             * its product. Checking only is_active would leave every unlisted
             * plan buyable by anyone who knows its id, which is precisely the
             * set of plans that are priced for somebody else: a retired tier
             * still held for legacy customers, an internal or staff plan, a
             * negotiated rate. GET /catalog/plans/{plan} 404s all of them, and
             * a checkout that accepts what the catalogue refuses to show is the
             * catalogue's visibility rule being enforced in one place only.
             */
            if ($plan === null || ! $plan->is_active || ! $plan->is_public) {
                throw CheckoutRejectedException::becausePlanIsUnavailable($id);
            }

            if ($plan->product === null || ! $plan->product->is_active || ! $plan->product->is_public) {
                throw CheckoutRejectedException::becausePlanIsUnavailable($id);
            }
        }

        return $plans;
    }

    /**
     * @param  Collection<string, Plan>  $plans
     * @return list<PricingLine>
     */
    private function buildPricingLines(Customer $customer, CheckoutRequest $request, Collection $plans): array
    {
        $lines = [];

        /*
         * Quantities already claimed by earlier lines of *this* basket.
         *
         * assertStock() counts what the database holds, and nothing in this
         * basket is in the database yet. Without this accumulator a basket that
         * names the same plan twice is checked twice against the same untouched
         * count, and two lines of one each both pass a limit of one — which
         * turns "one per customer" into "one per line" and oversells a plan
         * whose stock_limit is the whole reason it is listed.
         *
         * @var array<string, int> $claimedInThisBasket
         */
        $claimedInThisBasket = [];

        foreach ($request->lines as $line) {
            if ($line->quantity < 1) {
                throw CheckoutRejectedException::becauseQuantityIsInvalid($line->planId, $line->quantity);
            }

            /** @var Plan $plan */
            $plan = $plans->get($line->planId);

            $price = $plan->priceFor($customer->currency, $request->billingPeriod);

            if ($price === null) {
                throw CheckoutRejectedException::becausePlanIsNotSoldOnTheseTerms(
                    $line->planId,
                    $customer->currency,
                    $request->billingPeriod->value,
                );
            }

            $claimedInThisBasket[$line->planId] = ($claimedInThisBasket[$line->planId] ?? 0) + $line->quantity;

            $this->assertStock($customer, $plan, $claimedInThisBasket[$line->planId]);

            $lines[] = new PricingLine(
                description: $plan->nameFor($customer->users()->first()->locale ?? (string) config('app.locale')),
                quantity: $line->quantity,
                unitPrice: $price->recurring(),
                setupFee: $price->setup(),
                // A setup fee is not discountable by default: it covers real
                // one-off work, and a percentage coupon aimed at the recurring
                // price should not silently erode it.
                discountable: true,
            );
        }

        return $lines;
    }

    private function assertStock(Customer $customer, Plan $plan, int $quantity): void
    {
        /*
         * Checked at order time as well as at provisioning time. An order that
         * fails at provisioning has already taken the customer's money and
         * costs a refund plus the support conversation; an order that is never
         * accepted costs neither.
         *
         * This is a pre-check, not a reservation — the authoritative claim
         * happens when the paid order reserves capacity.
         */
        if ($plan->stock_limit !== null) {
            $sold = $this->soldCount($plan);

            if ($sold + $quantity > $plan->stock_limit) {
                throw CheckoutRejectedException::becausePlanIsOutOfStock((string) $plan->getKey());
            }
        }

        if ($plan->per_customer_limit !== null) {
            $owned = $this->soldCount($plan, $customer);

            if ($owned + $quantity > $plan->per_customer_limit) {
                throw CheckoutRejectedException::becausePerCustomerLimitReached(
                    (string) $plan->getKey(),
                    $plan->per_customer_limit,
                );
            }
        }
    }

    private function soldCount(Plan $plan, ?Customer $customer = null): int
    {
        return (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.plan_id', $plan->getKey())
            // Cancelled and refunded orders release their stock; everything
            // else still holds it, including orders awaiting payment, so a
            // basket cannot oversell while the customer is at the card form.
            ->whereNotIn('orders.status', [
                OrderStatus::Cancelled->value,
                OrderStatus::Refunded->value,
                OrderStatus::Terminated->value,
            ])
            ->when($customer !== null, fn ($q) => $q->where('orders.customer_id', $customer->getKey()))
            ->sum('order_items.quantity');
    }

    /**
     * @param  Collection<string, Plan>  $plans
     * @param  list<PricingLine>  $pricingLines
     */
    private function resolveCoupon(
        Customer $customer,
        CheckoutRequest $request,
        Collection $plans,
        array $pricingLines,
    ): ?Coupon {
        if ($request->couponCode === null || $request->couponCode === '') {
            return null;
        }

        $gross = array_reduce(
            $pricingLines,
            static fn (Money $carry, PricingLine $line): Money => $carry->plus($line->gross()),
            Money::zero($customer->currency),
        );

        return $this->coupons->validateCode($request->couponCode, new CouponContext(
            customer: $customer,
            orderAmount: $gross,
            planIds: $plans->keys()->all(),
            productKinds: $plans->pluck('product.kind')->filter()->map(
                static fn ($kind): string => is_string($kind) ? $kind : $kind->value
            )->unique()->values()->all(),
        ));
    }

    /**
     * @param  Collection<string, Plan>  $plans
     * @param  list<PricingLine>  $pricingLines
     */
    private function persist(
        Customer $customer,
        CheckoutRequest $request,
        Collection $plans,
        array $pricingLines,
        PricedOrder $priced,
        ?Coupon $coupon,
        ?User $placedBy,
    ): Order {
        try {
            return DB::transaction(function () use (
                $customer, $request, $plans, $pricingLines, $priced, $coupon, $placedBy
            ): Order {
                if ($coupon !== null) {
                    $this->assertCouponHasUnspentCapacity($customer, $coupon);
                }

                $order = Order::create([
                    'customer_id' => $customer->getKey(),
                    'placed_by_user_id' => $placedBy?->getKey(),
                    'number' => $this->numbers->next(),
                    'status' => OrderStatus::Draft,
                    'currency' => $priced->currency(),
                    'subtotal_minor' => $priced->subtotal->minorUnits(),
                    'discount_minor' => $priced->discount->minorUnits(),
                    'tax_minor' => $priced->tax->minorUnits(),
                    'total_minor' => $priced->total->minorUnits(),
                    'coupon_id' => $coupon?->getKey(),
                    'idempotency_key' => $request->idempotencyKey,
                    'request_fingerprint' => $request->idempotencyKey === null ? null : $request->fingerprint(),
                    'billing_snapshot' => $this->billingSnapshot($customer),
                    'notes' => $request->notes,
                ]);

                foreach ($request->lines as $index => $line) {
                    /** @var Plan $plan */
                    $plan = $plans->get($line->planId);
                    $pricingLine = $pricingLines[$index];
                    $lineTotal = $priced->lines[$index];

                    $order->items()->create([
                        'plan_id' => $plan->getKey(),
                        'kind' => 'plan',
                        // Snapshotted, not referenced: a later catalogue change
                        // must never rewrite what this customer bought.
                        'name' => $pricingLine->description,
                        'billing_period' => $request->billingPeriod,
                        'resources_snapshot' => $plan->resources,
                        'quantity' => $line->quantity,
                        'unit_recurring_minor' => $pricingLine->unitPrice->minorUnits(),
                        'unit_setup_minor' => $pricingLine->setupFee->minorUnits(),
                        'discount_minor' => $lineTotal->discount->minorUnits(),
                        'tax_minor' => $lineTotal->tax->minorUnits(),
                        'total_minor' => $lineTotal->total->minorUnits(),
                        'tax_rate' => $lineTotal->taxRate,
                        'tax_name' => $lineTotal->taxName,
                    ]);
                }

                /*
                 * Placed inside the same transaction as the insert.
                 *
                 * The order is still written as DRAFT and then transitioned, so
                 * the state machine validates the move and the transition table
                 * records how it got here — but committing the draft first made
                 * that draft visible to every other connection for as long as
                 * the second transaction took. Everything in the platform reads
                 * a draft order as one the customer has not placed: it is kept
                 * off the order list, it has no payment to start, and the loser
                 * of an idempotency race would be handed one and told it was
                 * their order. Two writes, one commit.
                 */
                $placed = $this->transition->execute(
                    $order,
                    $priced->isFree() ? OrderStatus::Paid : OrderStatus::PendingPayment,
                    actorType: $placedBy !== null ? 'user' : 'system',
                    actor: $placedBy,
                    reason: $priced->isFree() ? 'order total is zero' : 'checkout submitted',
                );

                /*
                 * Announced from inside the transaction and dispatched after it
                 * commits.
                 *
                 * Announcing it here rather than in execute() is what keeps a
                 * double-clicked buy button to one announcement: the loser of
                 * the idempotency race never reaches this line, it leaves
                 * through the catch below with the winner's order.
                 *
                 * Dispatch is held until the outermost transaction commits, for
                 * the same reason RecordPaymentCapture holds PaymentCaptured: a
                 * queued listener that dequeues before the order row is visible
                 * would treat a purchase that exists as one that does not. The
                 * level is checked rather than left to afterCommit's fallback,
                 * because with nothing open the announcement belongs now and not
                 * behind some other connection's commit.
                 */
                $announcement = new OrderPlaced(
                    orderId: (string) $placed->getKey(),
                    customerId: (string) $placed->customer_id,
                    total: $priced->total,
                    placedAt: ($placed->placed_at ?? now())->toImmutable(),
                );

                DB::afterCommit(static function () use ($announcement): void {
                    event($announcement);
                });

                return $placed;
            });
        } catch (UniqueConstraintViolationException) {
            /*
             * A concurrent submission with the same idempotency key won the
             * race. Its transaction has committed by the time the constraint
             * fired, so the row is now readable — return it rather than
             * surfacing a database error to a customer who merely clicked
             * twice.
             */
            $existing = $this->findByIdempotencyKey($customer, $request->idempotencyKey);

            if ($existing !== null) {
                return $existing;
            }

            throw new \RuntimeException('Order creation violated a unique constraint that was not the idempotency key.');
        }
    }

    /**
     * Refuses a coupon whose remaining uses are already spoken for.
     *
     * Redemption happens when the order is paid, and FulfilOrderOnInvoicePaid
     * deliberately does not withhold a paying customer's service when that
     * redemption is refused. So the counter bounds the audit trail and nothing
     * else: a one-use code placed on ten orders before any of them is paid
     * discounts ten invoices, fulfils ten orders, and still reports a single
     * redemption. The limit has to be enforced where the discount is granted.
     *
     * An order that has been placed and not yet redeemed holds a use, and stops
     * holding it when it is cancelled — the same treatment plan stock already
     * gets a few lines above. The coupon row is locked for the remainder of the
     * enclosing transaction so two simultaneous checkouts cannot both read the
     * same remaining capacity.
     *
     * @throws CouponFullyRedeemedException
     * @throws CouponCustomerLimitReachedException
     */
    private function assertCouponHasUnspentCapacity(Customer $customer, Coupon $coupon): void
    {
        // Read under the lock rather than from the caller's copy, which was
        // loaded before any of this transaction's competitors committed.
        $locked = DB::table('coupons')->where('id', $coupon->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            return;
        }

        $redeemed = (int) $locked->redemption_count;
        $globalLimit = $locked->max_redemptions === null ? null : (int) $locked->max_redemptions;

        if ($globalLimit !== null) {
            $claimed = $redeemed + $this->outstandingCouponHolds($coupon);

            if ($claimed >= $globalLimit) {
                throw CouponFullyRedeemedException::forCoupon(
                    (string) $coupon->getKey(),
                    $coupon->code,
                    $claimed,
                    $globalLimit,
                );
            }
        }

        $limit = (int) $locked->max_redemptions_per_customer;

        if ($limit <= 0) {
            return;
        }

        $used = $this->coupons->redemptionsBy($coupon, $customer)
            + $this->outstandingCouponHolds($coupon, $customer);

        if ($used >= $limit) {
            throw CouponCustomerLimitReachedException::forCustomer(
                (string) $coupon->getKey(),
                $coupon->code,
                (string) $customer->getKey(),
                $used,
                $limit,
            );
        }
    }

    /**
     * Orders that carry this coupon and have not yet redeemed it.
     *
     * Counted from orders that are still alive, and excluding any that already
     * has a redemption row — those are counted by redemption_count instead, and
     * counting them in both places would refuse the very order that is being
     * redeemed.
     */
    private function outstandingCouponHolds(Coupon $coupon, ?Customer $customer = null): int
    {
        return (int) DB::table('orders')
            ->where('orders.coupon_id', $coupon->getKey())
            ->whereNotIn('orders.status', [
                OrderStatus::Cancelled->value,
                OrderStatus::Refunded->value,
                OrderStatus::Terminated->value,
            ])
            ->whereNotExists(
                fn ($query) => $query->selectRaw('1')
                    ->from('coupon_redemptions')
                    ->whereColumn('coupon_redemptions.order_id', 'orders.id')
            )
            ->when($customer !== null, fn ($query) => $query->where('orders.customer_id', $customer->getKey()))
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function billingSnapshot(Customer $customer): array
    {
        return [
            'display_name' => $customer->display_name,
            'legal_name' => $customer->legal_name,
            'tax_id' => $customer->tax_id,
            'billing_email' => $customer->billing_email,
            'address_line1' => $customer->address_line1,
            'address_line2' => $customer->address_line2,
            'city' => $customer->city,
            'state' => $customer->state,
            'postal_code' => $customer->postal_code,
            'country' => $customer->country,
        ];
    }
}
