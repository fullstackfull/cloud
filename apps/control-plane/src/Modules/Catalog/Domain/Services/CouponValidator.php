<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Services;

use Brick\Math\RoundingMode;
use Lynomia\Modules\Catalog\Application\DTOs\CouponContext;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponCurrencyMismatchException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponCustomerLimitReachedException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponExpiredException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponFullyRedeemedException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponInactiveException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponNotApplicableException;
use Lynomia\Modules\Catalog\Domain\Exceptions\CouponNotYetValidException;
use Lynomia\Modules\Catalog\Domain\Exceptions\OrderBelowCouponMinimumException;
use Lynomia\Modules\Catalog\Domain\Exceptions\UnknownCouponException;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Decides whether a coupon may be used, and what it is worth.
 *
 * Every refusal is its own exception with its own error code, because the
 * checkout has to be able to say *why*: "that code expired" and "you have
 * already used that code" lead a customer to entirely different next steps,
 * and collapsing them into one "invalid coupon" turns a self-service problem
 * into a support ticket.
 *
 * Validation is deliberately free of side effects and safe to call as often as
 * the UI likes — it is what previews a discount before checkout. The counters
 * it reads are only authoritative under the row lock RedeemCoupon takes, which
 * is why redemption re-runs this same validation inside its transaction rather
 * than trusting the answer the preview gave.
 */
final class CouponValidator
{
    /**
     * The coupon a pasted code refers to, or null.
     */
    public function findByCode(string $code): ?Coupon
    {
        if (Coupon::normalizeCode($code) === '') {
            return null;
        }

        /*
         * `coupons_code_unique` is a unique index on the column verbatim, not
         * on upper(code), so nothing at the database level stops "save10" and
         * "SAVE10" both existing. Ordering by the primary key makes a pasted
         * code resolve to the same one of them every time: two customers with
         * the same code must never be quoted two different discounts because
         * the planner happened to return the rows in a different order.
         */
        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()->forCode($code)->orderBy('id')->first();

        return $coupon;
    }

    /**
     * @throws UnknownCouponException
     */
    public function resolveCode(string $code): Coupon
    {
        return $this->findByCode($code)
            ?? throw UnknownCouponException::forCode(Coupon::normalizeCode($code));
    }

    /**
     * @throws UnknownCouponException
     * @throws CouponInactiveException
     * @throws CouponNotYetValidException
     * @throws CouponExpiredException
     * @throws CouponFullyRedeemedException
     * @throws CouponCustomerLimitReachedException
     * @throws CouponCurrencyMismatchException
     * @throws OrderBelowCouponMinimumException
     * @throws CouponNotApplicableException
     */
    public function validateCode(string $code, CouponContext $context): Coupon
    {
        $coupon = $this->resolveCode($code);

        $this->validate($coupon, $context);

        return $coupon;
    }

    /**
     * Runs every rejection rule in turn, throwing on the first that fails.
     *
     * The order is chosen so the customer is told the most actionable thing
     * first: a coupon that is switched off or out of date can never be made to
     * work, whereas a basket that is a dinar short of the minimum can.
     */
    public function validate(Coupon $coupon, CouponContext $context): void
    {
        $this->assertActive($coupon);
        $this->assertWithinValidityWindow($coupon, $context);
        $this->assertHasGlobalCapacity($coupon);
        $this->assertWithinCustomerLimit($coupon, $context->customer);
        $this->assertCurrencyMatches($coupon, $context->orderAmount);
        $this->assertMeetsMinimum($coupon, $context->orderAmount);
        $this->assertAppliesToBasket($coupon, $context);
    }

    /**
     * What the coupon takes off this order.
     *
     * Capped at the order value: a 20 KWD voucher against a 9 KWD order is
     * worth 9 KWD, never a negative total that would read as the platform
     * owing the customer money. Change is not given on a coupon.
     */
    public function discountFor(Coupon $coupon, Money $orderAmount): Money
    {
        $this->assertCurrencyMatches($coupon, $orderAmount);

        $discount = $coupon->isPercentage()
            // The rate is an exact decimal string all the way from the numeric
            // column into Money, so 10% of 33.333 KWD rounds once, here, and
            // half-up as the invoice is read.
            ? $orderAmount->multipliedBy($coupon->percentageRate(), RoundingMode::HalfUp)
            : ($coupon->fixedAmount() ?? Money::zero($orderAmount->currency()));

        return $discount->isGreaterThan($orderAmount) ? $orderAmount : $discount;
    }

    /**
     * How many times this customer has already used the coupon.
     *
     * Counted from coupon_redemptions rather than from a per-customer counter,
     * so it cannot drift from the audit trail. The query runs on the coupon's
     * own connection, which is what makes it authoritative when RedeemCoupon
     * calls it while holding that row's lock.
     */
    public function redemptionsBy(Coupon $coupon, Customer $customer): int
    {
        return $coupon->redemptions()
            ->where('customer_id', $customer->getKey())
            ->count();
    }

    private function assertActive(Coupon $coupon): void
    {
        if (! $coupon->is_active) {
            throw CouponInactiveException::forCoupon((string) $coupon->getKey(), $coupon->code);
        }
    }

    private function assertWithinValidityWindow(Coupon $coupon, CouponContext $context): void
    {
        if ($coupon->valid_from !== null && $coupon->valid_from->greaterThan($context->at)) {
            throw CouponNotYetValidException::forCoupon(
                (string) $coupon->getKey(),
                $coupon->code,
                $coupon->valid_from,
            );
        }

        // valid_until is exclusive, so a coupon that runs "until midnight" is
        // dead at midnight rather than good for one more second.
        if ($coupon->valid_until !== null && $coupon->valid_until->lessThanOrEqualTo($context->at)) {
            throw CouponExpiredException::forCoupon(
                (string) $coupon->getKey(),
                $coupon->code,
                $coupon->valid_until,
            );
        }
    }

    private function assertHasGlobalCapacity(Coupon $coupon): void
    {
        if ($coupon->max_redemptions === null) {
            return;
        }

        if ($coupon->redemption_count >= $coupon->max_redemptions) {
            throw CouponFullyRedeemedException::forCoupon(
                (string) $coupon->getKey(),
                $coupon->code,
                $coupon->redemption_count,
                $coupon->max_redemptions,
            );
        }
    }

    private function assertWithinCustomerLimit(Coupon $coupon, Customer $customer): void
    {
        $limit = $coupon->max_redemptions_per_customer;

        if ($limit <= 0) {
            return;
        }

        $used = $this->redemptionsBy($coupon, $customer);

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
     * A coupon that names a currency may only be spent in that currency.
     *
     * Fixed-amount coupons always name one. A percentage coupon may also pin
     * itself to a currency to keep a campaign to one market, and when it does
     * the same rule holds — nothing here converts.
     */
    private function assertCurrencyMatches(Coupon $coupon, Money $orderAmount): void
    {
        if ($coupon->currency === null) {
            return;
        }

        $couponCurrency = strtoupper($coupon->currency);

        if ($couponCurrency !== $orderAmount->currency()) {
            throw CouponCurrencyMismatchException::between(
                (string) $coupon->getKey(),
                $coupon->code,
                $couponCurrency,
                $orderAmount->currency(),
            );
        }
    }

    private function assertMeetsMinimum(Coupon $coupon, Money $orderAmount): void
    {
        $minimum = $coupon->minimumOrderAmount($orderAmount->currency());

        if ($minimum === null) {
            return;
        }

        if ($orderAmount->isLessThan($minimum)) {
            throw OrderBelowCouponMinimumException::forOrder(
                (string) $coupon->getKey(),
                $coupon->code,
                $orderAmount,
                $minimum,
            );
        }
    }

    /**
     * A restricted campaign must cover the whole basket.
     *
     * The discount is computed over the order as a whole, so accepting a
     * partial match would spend campaign budget on lines it was never meant to
     * touch. An empty restriction list means "anything"; a non-empty one means
     * every plan and every kind on the order has to appear in it, and an order
     * carrying nothing to match against does not qualify.
     */
    private function assertAppliesToBasket(Coupon $coupon, CouponContext $context): void
    {
        $applicablePlanIds = $coupon->applicablePlanIds();

        if ($applicablePlanIds !== []) {
            $offending = array_values(array_diff($context->planIds, $applicablePlanIds));

            if ($context->planIds === [] || $offending !== []) {
                throw CouponNotApplicableException::forPlans(
                    (string) $coupon->getKey(),
                    $coupon->code,
                    $offending,
                );
            }
        }

        // Compared as raw strings on both sides. The coupon's list is not
        // filtered through ProductKind first (a value the enum has forgotten
        // would drop out and take the whole restriction with it), and neither
        // is the basket's (a kind we cannot parse must fail to match, not be
        // waved through).
        $applicableKinds = $coupon->applicableProductKindValues();

        if ($applicableKinds === []) {
            return;
        }

        $offendingKinds = array_values(array_diff($context->productKinds, $applicableKinds));

        if ($context->productKinds === [] || $offendingKinds !== []) {
            throw CouponNotApplicableException::forProductKinds(
                (string) $coupon->getKey(),
                $coupon->code,
                $offendingKinds,
            );
        }
    }
}
