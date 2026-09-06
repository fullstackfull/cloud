<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A tax rate expressed as an exact decimal, never a float.
 *
 * 15% is the string "0.15", not 0.15 as a double — the latter is actually
 * 0.1499999999999999944488848768742172978818416595458984375, which produces
 * off-by-one-fils errors on large invoices.
 *
 * @immutable
 */
final readonly class TaxRate
{
    private function __construct(
        public string $rate,
        public ?string $name,
        public bool $isInclusive,
    ) {}

    public static function of(string $rate, ?string $name = null, bool $isInclusive = false): self
    {
        return new self((string) BigDecimal::of($rate), $name, $isInclusive);
    }

    public static function zero(): self
    {
        return new self('0', null, false);
    }

    public function isZero(): bool
    {
        return BigDecimal::of($this->rate)->isZero();
    }

    /**
     * Tax on an amount that does not yet include it.
     *
     * Rounding is half-up at the line, which matches how invoices are read and
     * how every tax authority the platform targets expects them to add up.
     */
    public function taxOn(Money $net): Money
    {
        if ($this->isZero()) {
            return Money::zero($net->currency());
        }

        return $net->multipliedBy($this->rate, RoundingMode::HalfUp);
    }

    /**
     * Splits a tax-inclusive amount into its net and tax parts.
     *
     * net = gross ÷ (1 + rate), and tax is the remainder — computed by
     * subtraction rather than by rounding a second time, so net + tax is always
     * exactly the gross the customer was shown.
     *
     * @return array{net: Money, tax: Money}
     */
    public function splitInclusive(Money $gross): array
    {
        if ($this->isZero()) {
            return ['net' => $gross, 'tax' => Money::zero($gross->currency())];
        }

        $divisor = (string) BigDecimal::one()->plus(BigDecimal::of($this->rate));
        $net = $gross->dividedBy($divisor, RoundingMode::HalfUp);

        return ['net' => $net, 'tax' => $gross->minus($net)];
    }
}
