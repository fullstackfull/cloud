<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\ValueObjects;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One line submitted to the pricing engine, before discounts and tax.
 *
 * @immutable
 */
final readonly class PricingLine
{
    public function __construct(
        public string $description,
        public int $quantity,
        public Money $unitPrice,
        /** Setup fee charged once, added to the first period only. */
        public Money $setupFee,
        /** Whether a coupon may reduce this line. Setup fees often may not. */
        public bool $discountable = true,
    ) {}

    public function gross(): Money
    {
        return $this->unitPrice->multipliedBy($this->quantity)->plus($this->setupFee);
    }
}
