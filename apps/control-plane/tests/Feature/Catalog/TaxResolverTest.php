<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Domain\Services\TaxResolver;
use Lynomia\Modules\Catalog\Infrastructure\Models\TaxRule;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TaxResolverTest extends TestCase
{
    use RefreshDatabase;

    private TaxResolver $resolver;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = app(TaxResolver::class);
        $this->now = CarbonImmutable::parse('2026-06-01T12:00:00Z');
    }

    #[Test]
    public function a_state_rule_beats_the_country_wide_rule_it_sits_inside(): void
    {
        TaxRule::factory()->jurisdiction('KW')->rate('0.150000')->create();
        TaxRule::factory()->jurisdiction('KW', 'Hawalli')->rate('0.050000')->create(['name' => 'Hawalli VAT']);

        $stateRate = $this->resolver->resolve('KW', 'Hawalli', $this->now);

        $this->assertSame('0.050000', $stateRate->rate);
        $this->assertSame('Hawalli VAT', $stateRate->name);
    }

    #[Test]
    public function a_state_without_its_own_rule_falls_back_to_the_country_wide_rate(): void
    {
        TaxRule::factory()->jurisdiction('KW')->rate('0.150000')->create();
        TaxRule::factory()->jurisdiction('KW', 'Hawalli')->rate('0.050000')->create();

        $this->assertSame('0.150000', $this->resolver->resolve('KW', 'Salmiya', $this->now)->rate);
        $this->assertSame('0.150000', $this->resolver->resolve('KW', null, $this->now)->rate);
    }

    #[Test]
    public function a_state_is_matched_regardless_of_how_the_address_was_capitalised(): void
    {
        TaxRule::factory()->jurisdiction('US', 'California')->rate('0.072500')->create();

        $this->assertSame('0.072500', $this->resolver->resolve('us', ' california ', $this->now)->rate);
    }

    #[Test]
    public function an_expired_rule_does_not_apply(): void
    {
        TaxRule::factory()->jurisdiction('KW')->rate('0.150000')->create();
        TaxRule::factory()
            ->jurisdiction('KW', 'Hawalli')
            ->rate('0.050000')
            ->effective($this->now->subYear(), $this->now->subDay())
            ->create();

        // The state rule matched yesterday; today only the national rate is
        // in force, and the resolver must fall back rather than keep using it.
        $this->assertSame('0.150000', $this->resolver->resolve('KW', 'Hawalli', $this->now)->rate);
        $this->assertSame('0.050000', $this->resolver->resolve('KW', 'Hawalli', $this->now->subMonth())->rate);
    }

    #[Test]
    public function a_rule_that_has_not_come_into_force_yet_does_not_apply(): void
    {
        TaxRule::factory()->jurisdiction('KW')->rate('0.150000')->create();
        TaxRule::factory()
            ->jurisdiction('KW')
            ->rate('0.200000')
            ->effective($this->now->addMonth())
            ->create();

        $this->assertSame('0.150000', $this->resolver->resolve('KW', null, $this->now)->rate);
        $this->assertSame('0.200000', $this->resolver->resolve('KW', null, $this->now->addMonths(2))->rate);
    }

    #[Test]
    public function an_inactive_rule_is_ignored(): void
    {
        TaxRule::factory()->jurisdiction('KW')->rate('0.150000')->inactive()->create();

        $this->assertTrue($this->resolver->resolve('KW', null, $this->now)->isZero());
    }

    #[Test]
    public function an_invoice_resolves_the_rate_that_applied_when_it_was_issued(): void
    {
        $rateChange = CarbonImmutable::parse('2026-01-01T00:00:00Z');

        TaxRule::factory()
            ->jurisdiction('KW')
            ->rate('0.050000')
            ->effective($rateChange->subYears(3), $rateChange)
            ->create();
        TaxRule::factory()
            ->jurisdiction('KW')
            ->rate('0.150000')
            ->effective($rateChange)
            ->create();

        $issuedAt = $rateChange->subMonth();

        // Reissuing a 2025 invoice in 2026 must reproduce the 2025 figure, not
        // restate it at today's rate.
        $historic = $this->resolver->resolve('KW', null, $issuedAt);
        $current = $this->resolver->resolve('KW', null, $this->now);

        $this->assertSame('0.050000', $historic->rate);
        $this->assertSame('0.150000', $current->rate);
        $this->assertTrue($historic->taxOn(Money::ofMinor(100_000, 'KWD'))->equals(Money::ofMinor(5_000, 'KWD')));
    }

    #[Test]
    public function the_boundary_between_two_consecutive_rules_belongs_to_the_successor(): void
    {
        $changeover = CarbonImmutable::parse('2026-01-01T00:00:00Z');

        TaxRule::factory()->jurisdiction('KW')->rate('0.050000')
            ->effective($changeover->subYear(), $changeover)->create();
        TaxRule::factory()->jurisdiction('KW')->rate('0.150000')
            ->effective($changeover)->create();

        // Exactly one rule matches at the instant of the changeover.
        $this->assertSame('0.150000', $this->resolver->resolve('KW', null, $changeover)->rate);
        $this->assertSame('0.050000', $this->resolver->resolve('KW', null, $changeover->subSecond())->rate);
    }

    #[Test]
    public function a_jurisdiction_with_no_rule_is_zero_rated_rather_than_an_error(): void
    {
        TaxRule::factory()->jurisdiction('KW')->rate('0.150000')->create();

        $rate = $this->resolver->resolve('GB', null, $this->now);

        $this->assertTrue($rate->isZero());
        $this->assertNull($rate->name);
        $this->assertTrue($rate->taxOn(Money::ofMinor(100_000, 'KWD'))->isZero());
    }

    #[Test]
    public function an_address_with_no_country_is_zero_rated_rather_than_an_error(): void
    {
        TaxRule::factory()->jurisdiction('KW')->rate('0.150000')->create();

        $this->assertTrue($this->resolver->resolve(null, 'Hawalli', $this->now)->isZero());
        $this->assertTrue($this->resolver->resolve('', null, $this->now)->isZero());
    }

    #[Test]
    public function a_tax_exempt_customer_is_always_zero_rated(): void
    {
        TaxRule::factory()->jurisdiction('KW')->rate('0.150000')->create();
        TaxRule::factory()->jurisdiction('KW', 'Hawalli')->rate('0.050000')->create();

        $exempt = Customer::factory()->create([
            'country' => 'KW',
            'state' => 'Hawalli',
            'tax_exempt' => true,
        ]);
        $taxable = Customer::factory()->create([
            'country' => 'KW',
            'state' => 'Hawalli',
            'tax_exempt' => false,
        ]);

        $this->assertTrue($this->resolver->forCustomer($exempt, $this->now)->isZero());
        $this->assertSame('0.050000', $this->resolver->forCustomer($taxable, $this->now)->rate);
    }

    #[Test]
    public function a_rule_carries_its_inclusive_flag_into_the_resolved_rate(): void
    {
        TaxRule::factory()->jurisdiction('AE')->rate('0.050000')->inclusive()->create(['name' => 'UAE VAT']);

        $rate = $this->resolver->resolve('AE', null, $this->now);

        $this->assertTrue($rate->isInclusive);

        // 105.000 gross at an inclusive 5% is 100.000 net and 5.000 tax.
        ['net' => $net, 'tax' => $tax] = $rate->splitInclusive(Money::ofMinor(105_000, 'AED'));
        $this->assertTrue($net->equals(Money::ofMinor(100_000, 'AED')));
        $this->assertTrue($tax->equals(Money::ofMinor(5_000, 'AED')));
    }
}
