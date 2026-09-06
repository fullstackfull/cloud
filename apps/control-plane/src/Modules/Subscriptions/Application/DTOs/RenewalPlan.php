<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\DTOs;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * What the next invoice for a renewed subscription should contain.
 *
 * The period has already been advanced on the subscription row by the time
 * this exists, so the dates here are the *new* period — the one the invoice
 * covers. The coordinator prices the lines and issues the invoice; see the
 * module's integration notes.
 *
 * @immutable
 */
final readonly class RenewalPlan
{
    /**
     * @param  list<BillableLine>  $lines
     */
    public function __construct(
        public string $subscriptionId,
        public string $customerId,
        public string $currency,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public array $lines,
        /** A fixed-amount coupon still running on this subscription. */
        public ?Money $fixedDiscount = null,
        /** A percentage coupon, as an exact decimal such as "0.100". */
        public ?string $percentageDiscount = null,
        public ?string $couponCode = null,
        /** Cycles left after this renewal consumed one; null is unlimited. */
        public ?int $couponCyclesRemaining = null,
    ) {}

    /**
     * The lines in the shape PricingEngine::price() expects.
     *
     * @return list<PricingLine>
     */
    public function pricingLines(): array
    {
        return array_map(static fn (BillableLine $line): PricingLine => $line->line, $this->lines);
    }

    /**
     * The undiscounted, untaxed total — useful for logging and for tests, but
     * never for charging: the amount charged is whatever the pricing engine
     * makes of these lines.
     */
    public function gross(): Money
    {
        return array_reduce(
            $this->lines,
            static fn (Money $carry, BillableLine $line): Money => $carry->plus($line->amount()),
            Money::zero($this->currency),
        );
    }

    public function hasDiscount(): bool
    {
        return $this->fixedDiscount !== null || $this->percentageDiscount !== null;
    }
}
