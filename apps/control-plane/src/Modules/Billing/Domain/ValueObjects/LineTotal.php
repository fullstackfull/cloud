<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\ValueObjects;

use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * The fully-resolved money on one order or invoice line.
 *
 * Every component is retained rather than only the total, because an invoice
 * must be able to show — and a tax authority must be able to audit — how the
 * final figure was reached. Recomputing the breakdown later from the total
 * alone is not possible once rounding has been applied.
 *
 * @immutable
 */
final readonly class LineTotal
{
    public function __construct(
        /** Quantity × unit price, before anything is taken off. */
        public Money $gross,
        /** Discount attributed to this line. */
        public Money $discount,
        /** Taxable base: gross − discount. */
        public Money $net,
        public Money $tax,
        /** What the customer pays for this line: net + tax. */
        public Money $total,
        /** The rate applied, as an exact decimal string such as "0.150". */
        public string $taxRate,
        public ?string $taxName,
    ) {}

    public static function zero(string $currency): self
    {
        $zero = Money::zero($currency);

        return new self($zero, $zero, $zero, $zero, $zero, '0', null);
    }
}
