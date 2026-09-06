<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Application\DTOs;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * The money owed — or owed back — for a mid-cycle plan change.
 *
 * Both halves are kept, rather than only their difference, because the
 * customer has to be able to read on the invoice what was taken off for the
 * plan they left and what was charged for the plan they moved to. A single net
 * figure is unauditable, and it is also the figure that makes an upgrade
 * followed by an immediate downgrade look like it cost something.
 *
 * @immutable
 */
final readonly class ProrationPlan
{
    /**
     * @param  list<BillableLine>  $lines
     */
    public function __construct(
        public string $subscriptionId,
        public string $currency,
        public CarbonImmutable $changeAt,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        /** The unused remainder of the plan being left, as a negative amount. */
        public Money $credit,
        /** The same remainder priced at the new plan. */
        public Money $charge,
        public array $lines,
    ) {}

    /**
     * What the change actually costs: positive on an upgrade, negative on a
     * downgrade, and exactly zero when a change is undone within the same
     * instant — both halves are prorated over the same period, so the two
     * fractions are identical and cancel.
     */
    public function net(): Money
    {
        return $this->charge->plus($this->credit);
    }

    public function isUpgrade(): bool
    {
        return $this->net()->isPositive();
    }

    /**
     * @return list<PricingLine>
     */
    public function pricingLines(): array
    {
        return array_map(static fn (BillableLine $line): PricingLine => $line->line, $this->lines);
    }
}
