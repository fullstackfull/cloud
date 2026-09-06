<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\LineTotal;
use Lynomia\Modules\Billing\Domain\ValueObjects\PricingLine;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase
{
    private PricingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new PricingEngine;
    }

    private function line(string $unit, int $quantity = 1, string $setup = '0.000', bool $discountable = true): PricingLine
    {
        return new PricingLine(
            description: 'VPS plan',
            quantity: $quantity,
            unitPrice: Money::of($unit, 'KWD'),
            setupFee: Money::of($setup, 'KWD'),
            discountable: $discountable,
        );
    }

    // --- the closure invariant ------------------------------------------------

    #[Test]
    public function the_totals_are_exactly_the_sum_of_the_lines(): void
    {
        $priced = $this->engine->price(
            [$this->line('9.500'), $this->line('3.250', 3), $this->line('0.750', 2, setup: '1.000')],
            TaxRate::of('0.15', 'VAT'),
        );

        $sumOfLineTotals = array_reduce(
            $priced->lines,
            static fn (Money $carry, LineTotal $l): Money => $carry->plus($l->total),
            Money::zero('KWD'),
        );

        // An invoice whose lines do not add up to the amount charged is the
        // single most damaging billing defect there is.
        $this->assertTrue($priced->total->equals($sumOfLineTotals));
        $this->assertTrue($priced->total->equals($priced->subtotal->plus($priced->tax)));
    }

    #[Test]
    public function tax_is_computed_on_the_discounted_base(): void
    {
        $priced = $this->engine->price(
            [$this->line('100.000')],
            TaxRate::of('0.15', 'VAT'),
            percentageDiscount: '0.10',
        );

        $this->assertSame('10.000', $priced->discount->toDecimalString());
        $this->assertSame('90.000', $priced->subtotal->toDecimalString());
        // Taxing the pre-discount figure would overcharge the customer.
        $this->assertSame('13.500', $priced->tax->toDecimalString());
        $this->assertSame('103.500', $priced->total->toDecimalString());
    }

    // --- discount allocation ---------------------------------------------------

    #[Test]
    public function a_discount_is_allocated_across_lines_without_losing_a_fils(): void
    {
        // 10 KWD off three lines that cannot divide evenly.
        $priced = $this->engine->price(
            [$this->line('10.000'), $this->line('10.000'), $this->line('10.000')],
            TaxRate::zero(),
            fixedDiscount: Money::of('10.000', 'KWD'),
        );

        $allocated = array_reduce(
            $priced->lines,
            static fn (Money $carry, LineTotal $l): Money => $carry->plus($l->discount),
            Money::zero('KWD'),
        );

        $this->assertTrue(
            $allocated->equals(Money::of('10.000', 'KWD')),
            'Allocated discount must sum to exactly the discount granted.',
        );
        $this->assertSame('20.000', $priced->total->toDecimalString());
    }

    #[Test]
    public function a_discount_is_weighted_by_line_value(): void
    {
        // Lines of 10 and 30: a 20 KWD discount should split 5 / 15.
        $priced = $this->engine->price(
            [$this->line('10.000'), $this->line('30.000')],
            TaxRate::zero(),
            fixedDiscount: Money::of('20.000', 'KWD'),
        );

        $this->assertSame('5.000', $priced->lines[0]->discount->toDecimalString());
        $this->assertSame('15.000', $priced->lines[1]->discount->toDecimalString());
    }

    #[Test]
    public function a_non_discountable_line_is_excluded_from_the_percentage_base(): void
    {
        // A 10% coupon must not quietly discount a setup fee the catalogue
        // marks as non-discountable.
        $priced = $this->engine->price(
            [$this->line('100.000'), $this->line('50.000', discountable: false)],
            TaxRate::zero(),
            percentageDiscount: '0.10',
        );

        $this->assertSame('10.000', $priced->discount->toDecimalString());
        $this->assertSame('10.000', $priced->lines[0]->discount->toDecimalString());
        $this->assertTrue($priced->lines[1]->discount->isZero());
    }

    #[Test]
    public function a_coupon_larger_than_the_order_never_produces_a_negative_total(): void
    {
        $priced = $this->engine->price(
            [$this->line('10.000')],
            TaxRate::of('0.15'),
            fixedDiscount: Money::of('50.000', 'KWD'),
        );

        // A negative total would read as the platform owing the customer money.
        $this->assertTrue($priced->total->isZero());
        $this->assertSame('10.000', $priced->discount->toDecimalString());
        $this->assertTrue($priced->isFree());
    }

    // --- tax-inclusive pricing --------------------------------------------------

    #[Test]
    public function an_inclusive_price_splits_so_that_net_plus_tax_is_the_advertised_figure(): void
    {
        $priced = $this->engine->price(
            [$this->line('115.000')],
            TaxRate::of('0.15', 'VAT', isInclusive: true),
        );

        $this->assertSame('100.000', $priced->subtotal->toDecimalString());
        $this->assertSame('15.000', $priced->tax->toDecimalString());
        // The customer pays exactly the price they were shown.
        $this->assertSame('115.000', $priced->total->toDecimalString());
    }

    #[Test]
    public function an_inclusive_split_never_loses_a_minor_unit_to_double_rounding(): void
    {
        // 0.01 USD at 15% inclusive cannot divide cleanly; tax is the remainder
        // rather than a second rounded figure, so the parts still sum exactly.
        $rate = TaxRate::of('0.15', 'VAT', isInclusive: true);

        foreach (['0.01', '0.03', '0.07', '19.99', '1234.56'] as $gross) {
            $amount = Money::of($gross, 'USD');
            ['net' => $net, 'tax' => $tax] = $rate->splitInclusive($amount);

            $this->assertTrue(
                $net->plus($tax)->equals($amount),
                "Inclusive split of {$gross} USD must be exact.",
            );
        }
    }

    // --- currency safety ---------------------------------------------------------

    #[Test]
    public function mixing_currencies_in_one_order_is_refused(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $this->engine->price(
            [
                $this->line('10.000'),
                new PricingLine('USD line', 1, Money::of('10.00', 'USD'), Money::zero('USD')),
            ],
            TaxRate::zero(),
        );
    }

    #[Test]
    public function a_discount_in_another_currency_is_refused(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $this->engine->price(
            [$this->line('10.000')],
            TaxRate::zero(),
            fixedDiscount: Money::of('1.00', 'USD'),
        );
    }

    // --- proration ----------------------------------------------------------------

    #[Test]
    public function proration_charges_for_the_time_actually_remaining(): void
    {
        $start = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $end = $start->addMonthNoOverflow();

        $prorated = $this->engine->prorate(
            Money::of('31.000', 'KWD'),
            $start,
            $end,
            $start->addDays(16),
        );

        // 15 of 31 days remain on a 31 KWD month.
        $this->assertSame('15.000', $prorated->toDecimalString());
    }

    #[Test]
    public function proration_of_a_full_period_is_the_full_amount(): void
    {
        $start = CarbonImmutable::parse('2026-03-01T00:00:00Z');

        $this->assertSame(
            '9.000',
            $this->engine->prorate(Money::of('9.000', 'KWD'), $start, $start->addMonthNoOverflow(), $start)
                ->toDecimalString(),
        );
    }

    #[Test]
    public function proration_after_the_period_ends_is_zero(): void
    {
        $start = CarbonImmutable::parse('2026-03-01T00:00:00Z');
        $end = $start->addMonthNoOverflow();

        $this->assertTrue(
            $this->engine->prorate(Money::of('9.000', 'KWD'), $start, $end, $end->addDay())->isZero(),
        );
    }

    #[Test]
    public function an_upgrade_and_an_immediate_downgrade_cancel_out(): void
    {
        // Both sides use the same divisor, so repeated plan changes must not
        // leak a fils each time.
        $start = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        $end = $start->addMonthNoOverflow();
        $changeAt = $start->addDays(7)->addHours(13);

        $credit = $this->engine->prorate(Money::of('9.000', 'KWD'), $start, $end, $changeAt);
        $charge = $this->engine->prorate(Money::of('9.000', 'KWD'), $start, $end, $changeAt);

        $this->assertTrue($credit->minus($charge)->isZero());
    }

    #[Test]
    public function a_zero_length_period_prorates_to_zero_rather_than_dividing_by_zero(): void
    {
        $moment = CarbonImmutable::parse('2026-01-01T00:00:00Z');

        $this->assertTrue(
            $this->engine->prorate(Money::of('9.000', 'KWD'), $moment, $moment, $moment)->isZero(),
        );
    }

    // --- edge cases ----------------------------------------------------------------

    #[Test]
    public function pricing_an_empty_order_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->engine->price([], TaxRate::zero());
    }

    #[Test]
    public function a_setup_fee_is_charged_once_regardless_of_quantity(): void
    {
        $priced = $this->engine->price(
            [$this->line('10.000', quantity: 3, setup: '5.000')],
            TaxRate::zero(),
        );

        // 3 × 10 + 5, not 3 × (10 + 5).
        $this->assertSame('35.000', $priced->total->toDecimalString());
    }

    #[Test]
    public function a_zero_rate_produces_no_tax_line(): void
    {
        $priced = $this->engine->price([$this->line('10.000')], TaxRate::zero());

        $this->assertTrue($priced->tax->isZero());
        $this->assertSame('10.000', $priced->total->toDecimalString());
    }

    #[Test]
    public function two_decimal_currencies_are_handled_with_the_same_rules(): void
    {
        $priced = $this->engine->price(
            [new PricingLine('Plan', 3, Money::of('19.99', 'USD'), Money::zero('USD'))],
            TaxRate::of('0.20', 'VAT'),
            percentageDiscount: '0.15',
        );

        // 59.97 gross, 8.9955 → 9.00 discount, 50.97 net, 10.194 → 10.19 tax.
        $this->assertSame('59.97', $priced->lines[0]->gross->toDecimalString());
        $this->assertSame('9.00', $priced->discount->toDecimalString());
        $this->assertSame('50.97', $priced->subtotal->toDecimalString());
        $this->assertSame('10.19', $priced->tax->toDecimalString());
        $this->assertSame('61.16', $priced->total->toDecimalString());
    }
}
