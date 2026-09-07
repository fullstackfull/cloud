<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use Brick\Math\RoundingMode;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\Exceptions\InvalidMoneyException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Money is the foundation of every invoice the platform will ever issue, so it
 * is tested against the properties that actually matter commercially:
 * exactness, currency safety, and conservation of value under division.
 */
final class MoneyTest extends TestCase
{
    #[Test]
    public function it_stores_three_decimal_currencies_without_loss(): void
    {
        // KWD has three minor digits. A platform that assumes two would
        // silently lose a factor of ten on every Kuwaiti invoice.
        $amount = Money::of('12.500', 'KWD');

        $this->assertSame(12500, $amount->minorUnits());
        $this->assertSame('12.500', $amount->toDecimalString());
        $this->assertSame('KWD', $amount->currency());
    }

    #[Test]
    public function it_stores_two_decimal_currencies_without_loss(): void
    {
        $amount = Money::of('19.99', 'USD');

        $this->assertSame(1999, $amount->minorUnits());
        $this->assertSame('19.99', $amount->toDecimalString());
    }

    #[Test]
    public function it_round_trips_through_minor_units(): void
    {
        $original = Money::of('1234.567', 'KWD');
        $restored = Money::ofMinor($original->minorUnits(), $original->currency());

        $this->assertTrue($original->equals($restored));
    }

    #[Test]
    public function it_refuses_to_combine_different_currencies(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('10.000', 'KWD')->plus(Money::of('10.00', 'USD'));
    }

    #[Test]
    public function it_refuses_an_amount_with_more_precision_than_the_currency_allows(): void
    {
        // Without an explicit rounding mode this must throw rather than quietly
        // discard the extra digit.
        $this->expectException(InvalidMoneyException::class);

        Money::of('19.999', 'USD');
    }

    #[Test]
    public function it_accepts_extra_precision_when_rounding_is_explicit(): void
    {
        $rounded = Money::of('19.999', 'USD', RoundingMode::HalfUp);

        $this->assertSame(2000, $rounded->minorUnits());
    }

    #[Test]
    public function splitting_conserves_the_total_exactly(): void
    {
        // 12.500 KWD into three parts cannot divide evenly. The remainder must
        // be distributed, never dropped.
        $total = Money::of('12.500', 'KWD');
        $parts = $total->allocateEvenly(3);

        $this->assertCount(3, $parts);

        $sum = array_reduce(
            $parts,
            static fn (Money $carry, Money $part): Money => $carry->plus($part),
            Money::zero('KWD'),
        );

        $this->assertTrue($sum->equals($total), 'Splitting money must not create or destroy minor units.');
    }

    #[Test]
    public function weighted_allocation_conserves_the_total_exactly(): void
    {
        $total = Money::of('100.001', 'KWD');
        $parts = $total->allocate([1, 2, 1]);

        $sum = array_reduce(
            $parts,
            static fn (Money $carry, Money $part): Money => $carry->plus($part),
            Money::zero('KWD'),
        );

        $this->assertTrue($sum->equals($total));
    }

    #[Test]
    public function it_computes_tax_with_an_explicit_rounding_decision(): void
    {
        $net = Money::of('100.000', 'KWD');
        $vat = $net->multipliedBy('0.15', RoundingMode::HalfUp);

        $this->assertSame('15.000', $vat->toDecimalString());
        $this->assertSame('115.000', $net->plus($vat)->toDecimalString());
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function prorationCases(): iterable
    {
        yield 'one day of a 30-day month' => ['9.000', 30, '0.300'];
        yield 'one day of a 31-day month' => ['9.300', 31, '0.300'];
        yield 'indivisible remainder rounds half up' => ['10.000', 3, '3.333'];
    }

    #[Test]
    #[DataProvider('prorationCases')]
    public function it_prorates_a_monthly_price(string $monthly, int $days, string $expectedDaily): void
    {
        $daily = Money::of($monthly, 'KWD')->dividedBy($days, RoundingMode::HalfUp);

        $this->assertSame($expectedDaily, $daily->toDecimalString());
    }

    #[Test]
    public function comparisons_are_currency_safe(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('10.000', 'KWD')->isGreaterThan(Money::of('1.00', 'USD'));
    }

    #[Test]
    public function it_reports_sign_correctly(): void
    {
        $this->assertTrue(Money::zero('KWD')->isZero());
        $this->assertTrue(Money::of('0.001', 'KWD')->isPositive());
        $this->assertTrue(Money::of('0.001', 'KWD')->negated()->isNegative());
        $this->assertSame('0.001', Money::of('0.001', 'KWD')->negated()->absolute()->toDecimalString());
    }

    #[Test]
    public function it_formats_for_a_locale_at_all(): void
    {
        // This method called a brick/money method that does not exist, and no
        // test called this method, so the two absences cancelled out and the
        // failure waited for the first rendered invoice.
        $formatted = Money::of('9.000', 'KWD')->format('en');

        // Asserted in pieces rather than as one literal: ICU separates the
        // code from the amount with a non-breaking space, and a test that
        // pastes one in reads as though the two strings were identical when
        // it fails.
        $this->assertStringContainsString('KWD', $formatted);
        $this->assertStringContainsString('9.000', $formatted);
    }

    #[Test]
    public function it_formats_arabic_with_western_numerals(): void
    {
        $formatted = Money::of('9.000', 'KWD')->format('ar');

        // The portal asks Intl for Latin digits explicitly; a document the
        // server renders must not disagree with the screen the customer is
        // looking at. Arabic-Indic digits here would also break copy-paste
        // into a bank reference field.
        $this->assertStringContainsString('9.000', $formatted);
        $this->assertDoesNotMatchRegularExpression('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $formatted);
    }

    #[Test]
    public function it_leaves_a_locale_that_names_its_own_numbering_system_alone(): void
    {
        $formatted = Money::of('9.000', 'KWD')->format('ar-u-nu-arab');

        $this->assertMatchesRegularExpression('/[\x{0660}-\x{0669}]/u', $formatted);
    }

    #[Test]
    public function it_serialises_to_json_without_a_float(): void
    {
        $json = json_encode(Money::of('12.500', 'KWD'), JSON_THROW_ON_ERROR);

        $this->assertSame(
            '{"minor_units":12500,"currency":"KWD","amount":"12.500"}',
            $json,
        );
    }
}
