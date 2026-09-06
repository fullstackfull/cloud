<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\ValueObjects;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A complete, arithmetically closed pricing result.
 *
 * The invariant this type exists to carry: the totals equal the sum of the
 * lines, exactly, with no residual minor unit anywhere. Discounts are allocated
 * across lines rather than applied to the total, so a percentage coupon on an
 * order whose lines have different tax rates still produces a correct tax
 * figure.
 *
 * @immutable
 */
final readonly class PricedOrder
{
    /**
     * @param  list<LineTotal>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public Money $discount,
        public Money $tax,
        public Money $total,
        public ?string $couponCode = null,
    ) {}

    public function currency(): string
    {
        return $this->total->currency();
    }

    public function isFree(): bool
    {
        return $this->total->isZero();
    }
}
