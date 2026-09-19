<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Application\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricedOrder;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Catalog\Application\DTOs\CouponContext;
use Lynomia\Modules\Catalog\Domain\Services\CouponValidator;
use Lynomia\Modules\Catalog\Domain\Services\TaxResolver;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Application\DTOs\PricedCheckout;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssertProductMaySell;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * What a basket costs.
 *
 * This is the only place in the platform that answers that question. It was
 * extracted from PlaceOrder when the catalogue gained a quote — the screen
 * that tells a customer the price before they commit — because a second
 * implementation of pricing is a promise the platform will eventually break:
 * a coupon rule, a tax boundary or a rounding decision changes on one side,
 * the quote says one number, the invoice says another, and the customer is
 * right to be angry.
 *
 * So both callers run this, in this order, with no shortcuts on either side:
 *
 *   1. load the plans and refuse anything the catalogue does not sell;
 *   2. refuse a product the readiness engine says may not be sold today;
 *   3. build the priced lines from the catalogue's price for the customer's
 *      own currency and the requested period — never from the request;
 *   4. resolve the customer's tax rate for today;
 *   5. validate the coupon, if there is one;
 *   6. let PricingEngine allocate the discount and the tax.
 *
 * Nothing here writes. A quote takes no stock, holds no coupon and creates
 * nothing; the checks it runs are the same checks, so a basket that quotes is
 * a basket that can be ordered, but a quote that is never ordered leaves no
 * trace.
 */
final readonly class OrderPricing
{
    public function __construct(
        private PricingEngine $pricing,
        private TaxResolver $taxResolver,
        private CouponValidator $coupons,
        private AssertProductMaySell $sellable,
    ) {}

    /**
     * @throws CheckoutRejectedException
     */
    public function execute(Customer $customer, CheckoutRequest $request): PricedCheckout
    {
        if ($request->lines === []) {
            throw CheckoutRejectedException::becauseBasketIsEmpty();
        }

        if (! $customer->canPurchase()) {
            throw CheckoutRejectedException::becauseAccountCannotPurchase($customer->status->value);
        }

        $plans = $this->loadPlans($request);
        $this->assertEveryLineIsOnSale($plans);
        $pricingLines = $this->buildPricingLines($customer, $request, $plans);

        $taxRate = $this->taxResolver->forCustomer($customer, now());
        $coupon = $this->resolveCoupon($customer, $request, $plans, $pricingLines);

        return new PricedCheckout(
            plans: $plans,
            pricingLines: $pricingLines,
            taxRate: $taxRate,
            coupon: $coupon,
            priced: $this->price($pricingLines, $taxRate, $coupon, $request->couponCode),
            renewal: $this->renewal($pricingLines, $taxRate),
        );
    }

    /**
     * What the same lines cost when they come round again.
     *
     * Two things are deliberately dropped: the setup fee, which is one-off
     * work that is not done twice, and the coupon, which discounted this
     * purchase and not every future one. Everything else — the plan price in
     * the customer's currency, the tax rule for where they are — is priced by
     * the same engine, so the renewal figure is arrived at the same way as the
     * figure on the invoice rather than by multiplying something in a browser.
     *
     * It is a projection of today's catalogue and today's tax rule, not a
     * promise: the screen that shows it says so. What it is not is a guess.
     *
     * @param  list<PricingLine>  $pricingLines
     */
    public function renewal(array $pricingLines, TaxRate $taxRate): PricedOrder
    {
        $recurringOnly = array_map(
            static fn (PricingLine $line): PricingLine => new PricingLine(
                description: $line->description,
                quantity: $line->quantity,
                unitPrice: $line->unitPrice,
                setupFee: Money::zero($line->unitPrice->currency()),
                discountable: $line->discountable,
            ),
            $pricingLines,
        );

        return $this->pricing->price($recurringOnly, $taxRate);
    }

    /**
     * The readiness guard, once per product kind in the basket.
     *
     * A plan's kind is the product the readiness engine assesses — a VPS
     * plan is the VPS product, whatever tier it is. In production a kind
     * that is not ready_to_sell is refused before pricing, stock or coupons
     * are consulted, because none of those matters for a thing the platform
     * has decided it may not sell today. Outside production the guard stands
     * aside; see AssertProductMaySell.
     *
     * @param  Collection<string, Plan>  $plans
     */
    private function assertEveryLineIsOnSale(Collection $plans): void
    {
        $kinds = [];

        foreach ($plans as $plan) {
            if ($plan->product !== null) {
                $kinds[$plan->product->kind->value] = Product::from($plan->product->kind->value);
            }
        }

        foreach ($kinds as $product) {
            $this->sellable->execute($product);
        }
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
}
