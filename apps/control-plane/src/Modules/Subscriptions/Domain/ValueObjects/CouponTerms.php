<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\ValueObjects;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * The part of a coupon a renewal needs to know about.
 *
 * A renewal does not redeem a coupon — redemption happened once, at checkout.
 * What it needs is the discount to re-apply and whether the coupon was sold as
 * a recurring one, so the terms are read as a value rather than as a mutable
 * row the subscription might be tempted to write to.
 *
 * @immutable
 */
final readonly class CouponTerms
{
    public function __construct(
        public string $id,
        public string $code,
        /** Whether this coupon was sold as applying to every renewal. */
        public bool $appliesToRenewals,
        /** An exact decimal such as "0.100", or null for a fixed-amount coupon. */
        public ?string $percentage,
        /** A fixed discount in the coupon's own currency, or null. */
        public ?Money $fixedAmount,
        /** How many cycles the coupon was sold for; null means indefinitely. */
        public ?int $durationCycles,
    ) {}

    /**
     * Whether the coupon can still reduce a renewal.
     *
     * A null cycle count is unlimited, matching a coupon issued with no
     * duration: the customer keeps the discount for as long as they keep the
     * subscription.
     */
    public function appliesTo(?int $cyclesRemaining): bool
    {
        if (! $this->appliesToRenewals) {
            return false;
        }

        return $cyclesRemaining === null || $cyclesRemaining > 0;
    }

    /**
     * The fixed discount, but only when it is denominated in the currency
     * being billed.
     *
     * A coupon issued in another currency is dropped rather than converted:
     * the platform never converts between currencies, and a converted discount
     * is a discount nobody authorised.
     */
    public function fixedAmountIn(string $currency): ?Money
    {
        if ($this->fixedAmount === null) {
            return null;
        }

        return $this->fixedAmount->currency() === strtoupper($currency)
            ? $this->fixedAmount
            : null;
    }
}
