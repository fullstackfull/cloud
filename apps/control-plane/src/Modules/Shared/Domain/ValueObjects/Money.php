<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\ValueObjects;

use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Brick\Money\AllocationMode;
use Brick\Money\Money as BrickMoney;
use Brick\Money\SplitMode;
use JsonSerializable;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\Exceptions\InvalidMoneyException;
use Stringable;

/**
 * An exact monetary amount.
 *
 * Money is stored and manipulated as an integer number of minor units (fils,
 * cents, …) together with an ISO-4217 currency. Floating point is never used
 * anywhere in the lifecycle: not in construction, not in arithmetic, not in
 * persistence.
 *
 * Every operation that can lose precision requires an explicit rounding mode,
 * so a rounding decision is always a visible decision at the call site.
 *
 * @immutable
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(
        private BrickMoney $amount,
    ) {}

    /**
     * Build from an integer number of minor units — the canonical constructor,
     * and the only one used when reading from the database.
     */
    public static function ofMinor(int $minorUnits, string $currency): self
    {
        try {
            return new self(BrickMoney::ofMinor($minorUnits, strtoupper($currency)));
        } catch (MathException|\InvalidArgumentException|\UnexpectedValueException $e) {
            throw InvalidMoneyException::forMinorUnits($minorUnits, $currency, $e);
        }
    }

    /**
     * Build from a decimal string such as "12.500". Never pass a float here:
     * the signature accepts int|string precisely so that a float cannot be
     * silently coerced and rounded by PHP before we ever see it.
     */
    public static function of(int|string $amount, string $currency, RoundingMode $roundingMode = RoundingMode::Unnecessary): self
    {
        try {
            return new self(BrickMoney::of($amount, strtoupper($currency), roundingMode: $roundingMode));
        } catch (MathException|\InvalidArgumentException|\UnexpectedValueException $e) {
            throw InvalidMoneyException::forAmount((string) $amount, $currency, $e);
        }
    }

    public static function zero(string $currency): self
    {
        return new self(BrickMoney::zero(strtoupper($currency)));
    }

    public function currency(): string
    {
        return $this->amount->getCurrency()->getCurrencyCode();
    }

    /**
     * The integer number of minor units. This is what gets persisted.
     */
    public function minorUnits(): int
    {
        return $this->amount->getMinorAmount()->toInt();
    }

    /**
     * Exact decimal representation, e.g. "12.500" for KWD.
     */
    public function toDecimalString(): string
    {
        return (string) $this->amount->getAmount();
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->plus($other->amount));
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount->minus($other->amount));
    }

    /**
     * Multiply by an exact quantity or rate. A rate is passed as a decimal
     * string ("0.15"), never as a float.
     */
    public function multipliedBy(int|string $multiplier, RoundingMode $roundingMode = RoundingMode::Unnecessary): self
    {
        return new self($this->amount->multipliedBy($multiplier, $roundingMode));
    }

    public function dividedBy(int|string $divisor, RoundingMode $roundingMode = RoundingMode::HalfUp): self
    {
        return new self($this->amount->dividedBy($divisor, $roundingMode));
    }

    /**
     * Split into $parts amounts whose sum is exactly this amount. Remainder
     * minor units are distributed one each to the leading parts, so proration
     * never loses or invents a fil.
     *
     * @return list<self>
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw InvalidMoneyException::forAllocation($parts);
        }

        return array_map(
            static fn (BrickMoney $part): self => new self($part),
            $this->amount->split($parts, SplitMode::ToFirst),
        );
    }

    /**
     * Allocate according to integer weights, preserving the exact total.
     *
     * @param  non-empty-list<int>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        return array_map(
            static fn (BrickMoney $part): self => new self($part),
            $this->amount->allocate($ratios, AllocationMode::FloorToLargestRemainder),
        );
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    public function absolute(): self
    {
        return new self($this->amount->abs());
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount->isGreaterThan($other->amount);
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount->isGreaterThanOrEqualTo($other->amount);
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount->isLessThan($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency() === $other->currency()
            && $this->minorUnits() === $other->minorUnits();
    }

    /**
     * Localised presentation. Formatting is a presentation concern and never
     * feeds back into arithmetic.
     *
     * The portal formats client-side from the minor units and the currency
     * code, so nothing in the HTTP layer calls this. It exists for the places
     * that cannot — a PDF invoice, a dunning email — and it is covered by a
     * test because a method with no caller is a method nobody notices is
     * broken: this one called `formatTo()`, which brick/money does not have,
     * and would have raised a fatal error the first time an invoice was
     * rendered.
     */
    public function format(string $locale = 'en'): string
    {
        return $this->amount->formatToLocale(self::withWesternNumerals($locale));
    }

    /**
     * Western digits, whatever the locale would have chosen.
     *
     * The portal already asks Intl for `-u-nu-latn` explicitly, for a reason
     * that applies at least as much to a document: an amount a customer may
     * have to quote back to their bank has to be copy-pasteable, and Arabic
     * script digits are not what a bank's reference field expects. A server
     * rendering the same invoice in Arabic-Indic digits would make the emailed
     * total and the on-screen total look like different numbers.
     *
     * A locale that already names a numbering system is left alone: it was
     * asked for on purpose.
     */
    private static function withWesternNumerals(string $locale): string
    {
        if (str_contains($locale, 'nu-') || str_contains($locale, 'numbers=')) {
            return $locale;
        }

        return $locale.'-u-nu-latn';
    }

    /**
     * @return array{minor_units: int, currency: string, amount: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'minor_units' => $this->minorUnits(),
            'currency' => $this->currency(),
            'amount' => $this->toDecimalString(),
        ];
    }

    public function __toString(): string
    {
        return $this->toDecimalString().' '.$this->currency();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency() !== $other->currency()) {
            throw CurrencyMismatchException::between($this->currency(), $other->currency());
        }
    }
}
