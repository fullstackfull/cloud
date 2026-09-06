<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Infrastructure\Repositories;

use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Subscriptions\Domain\ValueObjects\CouponTerms;

/**
 * Reads the terms of the coupon attached to a subscription.
 *
 * Deliberately a read: coupon state — validity windows, redemption limits and
 * the redemption counter — belongs to the catalogue, and is only ever moved by
 * RedeemCoupon under a row lock. A renewal is not a redemption; it re-applies a
 * discount the customer has already redeemed once, so it narrows the coupon to
 * the handful of fields it prices with and touches nothing.
 *
 * A deactivated coupon stops discounting renewals as well as new checkouts.
 * That is the point of the switch: a coupon that was mispriced or abused is
 * usually already attached to live subscriptions, and honouring it there
 * indefinitely would leave an operator no way to stop it. Cycles are not
 * consumed while it is off, so re-activating one restores what the customer
 * had left.
 */
final class CouponTermsRepository
{
    public function find(?string $couponId): ?CouponTerms
    {
        if ($couponId === null) {
            return null;
        }

        /** @var Coupon|null $coupon */
        $coupon = Coupon::query()
            ->whereKey($couponId)
            ->where('is_active', true)
            ->first();

        if ($coupon === null) {
            return null;
        }

        return new CouponTerms(
            id: (string) $coupon->getKey(),
            code: $coupon->code,
            appliesToRenewals: (bool) $coupon->applies_to_renewals,
            // Exact decimal string throughout — the rate is never a float, and
            // a fixed-amount coupon has no rate at all.
            percentage: $coupon->isPercentage() ? $coupon->percentageRate() : null,
            fixedAmount: $coupon->fixedAmount(),
            durationCycles: $coupon->duration_cycles,
        );
    }
}
