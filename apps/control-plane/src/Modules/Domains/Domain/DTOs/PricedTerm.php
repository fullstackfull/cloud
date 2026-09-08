<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\DTOs;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * What one operation on one name, for one term, costs and sells for.
 *
 * Both sides travel together on purpose. A price without the cost beside it is
 * a number nobody can reconcile a margin against once the price list has moved
 * on, and this platform's price list will move.
 *
 * `cost` is nullable and that is not laziness: a TLD whose wholesale price the
 * platform has not been told still has a price it sells at. Recording an
 * invented cost would put a fictional margin in the ledger.
 */
final readonly class PricedTerm
{
    public function __construct(
        public Money $price,
        public ?Money $cost,
        public bool $premium,
        /** The registry's own handle on a premium quote, where it gave one. */
        public ?string $providerReference = null,
    ) {}

    /**
     * What the platform keeps, where both sides are known.
     */
    public function margin(): ?Money
    {
        return $this->cost === null ? null : $this->price->minus($this->cost);
    }
}
