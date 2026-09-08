<?php

declare(strict_types=1);

namespace Tests\Feature\Domains;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Domains\Application\Actions\QuoteDomain;
use Lynomia\Modules\Domains\Application\Actions\RedeemQuote;
use Lynomia\Modules\Domains\Domain\DTOs\AvailabilityAnswer;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Domain\Exceptions\DomainRefusedException;
use Lynomia\Modules\Domains\Domain\ValueObjects\RegistrableDomain;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainQuote;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a domain costs, and why the customer cannot decide.
 */
final class PricingADomainTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->tld = DomainTld::factory()->onSale()->named('test')->create();
    }

    private function parse(string $name): RegistrableDomain
    {
        return RegistrableDomain::parse($name, ['test']);
    }

    #[Test]
    public function a_registration_is_priced_from_the_list_and_records_what_it_cost(): void
    {
        $quote = app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('ordinary.test'),
            DomainOperationKind::Register,
        );

        $this->assertSame(3_500, $quote->price_minor);
        // Both sides written down, so a margin months later is reconcilable
        // against what was actually charged rather than today's price list.
        $this->assertSame(2_800, $quote->cost_minor);
        $this->assertFalse($quote->premium);
    }

    #[Test]
    public function a_renewal_is_dearer_than_a_registration_because_it_is(): void
    {
        $registration = app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('ordinary.test'),
            DomainOperationKind::Register,
        );

        $renewal = app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('ordinary.test'),
            DomainOperationKind::Renew,
        );

        // The shape of the whole industry, and the reason five prices exist
        // rather than one.
        $this->assertGreaterThan($registration->price_minor, $renewal->price_minor);
    }

    #[Test]
    public function a_multi_year_term_multiplies_both_sides(): void
    {
        $quote = app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('longterm.test'),
            DomainOperationKind::Register,
            termYears: 3,
        );

        $this->assertSame(10_500, $quote->price_minor);
        $this->assertSame(8_400, $quote->cost_minor);
        $this->assertSame(3, $quote->term_years);
    }

    #[Test]
    public function a_term_the_registry_will_not_accept_is_refused_before_any_money_moves(): void
    {
        $this->tld->update(['maximum_term_years' => 2]);

        $this->expectException(DomainRefusedException::class);

        app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('toolong.test'),
            DomainOperationKind::Register,
            termYears: 5,
        );
    }

    #[Test]
    public function a_premium_name_is_priced_from_the_registry_with_the_margin_kept(): void
    {
        $quote = app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('posh.test'),
            DomainOperationKind::Register,
            answer: AvailabilityAnswer::premium(
                'posh.test',
                Money::ofMinor(250_000, 'KWD'),
                'registry-quote-abc',
            ),
        );

        $this->assertTrue($quote->premium);

        /*
         * The registry wants 250 000; the platform's ordinary margin on this
         * TLD is 3 500 sold against 2 800 cost. Selling a premium name at cost
         * would be donating the difference on exactly the names where the
         * money is.
         */
        $this->assertSame(250_000, $quote->cost_minor);
        $this->assertSame((int) ceil(250_000 * 3_500 / 2_800), $quote->price_minor);

        // The registry's own handle, kept, because a registry that quoted a
        // price expects to see the quote back with the order.
        $this->assertSame('registry-quote-abc', $quote->provider_reference);
    }

    #[Test]
    public function a_premium_name_cannot_be_bought_at_an_ordinary_price(): void
    {
        /*
         * The attack the quote table exists for. The customer is handed an id;
         * the amount never travels through the browser, so the only thing a
         * tampered request can change is which quote is redeemed — and that is
         * checked against the account and the operation.
         */
        $ordinary = app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('cheap.test'),
            DomainOperationKind::Register,
        );

        $premium = app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('posh.test'),
            DomainOperationKind::Register,
            answer: AvailabilityAnswer::premium('posh.test', Money::ofMinor(250_000, 'KWD')),
        );

        // Two rows, two names, two prices. Redeeming the cheap quote buys the
        // cheap name; there is no field that says otherwise.
        $redeemed = app(RedeemQuote::class)->execute(
            $this->customer,
            (string) $ordinary->getKey(),
            DomainOperationKind::Register,
        );

        $this->assertSame('cheap.test', $redeemed->name);
        $this->assertSame(3_500, $redeemed->price_minor);
        $this->assertNotSame($premium->price_minor, $redeemed->price_minor);
    }

    #[Test]
    public function another_accounts_quote_is_not_found_rather_than_forbidden(): void
    {
        $theirs = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $quote = app(QuoteDomain::class)->execute(
            $theirs,
            $this->parse('theirs.test'),
            DomainOperationKind::Register,
        );

        // A 403 would confirm that another account priced this name.
        $this->expectException(DomainRefusedException::class);

        app(RedeemQuote::class)->execute(
            $this->customer,
            (string) $quote->getKey(),
            DomainOperationKind::Register,
        );
    }

    #[Test]
    public function a_quote_is_spent_once(): void
    {
        $quote = app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('once.test'),
            DomainOperationKind::Register,
        );

        app(RedeemQuote::class)->execute($this->customer, (string) $quote->getKey(), DomainOperationKind::Register);

        try {
            app(RedeemQuote::class)->execute($this->customer, (string) $quote->getKey(), DomainOperationKind::Register);
            $this->fail('a quote should not be redeemable twice');
        } catch (DomainRefusedException $e) {
            $this->assertSame('domain.quote_spent', $e->errorCode());
        }
    }

    #[Test]
    public function an_expired_quote_is_refused(): void
    {
        $quote = DomainQuote::factory()->expired()->create([
            'customer_id' => $this->customer->getKey(),
        ]);

        try {
            app(RedeemQuote::class)->execute($this->customer, (string) $quote->getKey(), DomainOperationKind::Register);
            $this->fail('an expired quote should not be redeemable');
        } catch (DomainRefusedException $e) {
            $this->assertSame('domain.quote_expired', $e->errorCode());
        }
    }

    #[Test]
    public function a_registration_quote_cannot_be_redeemed_as_a_renewal(): void
    {
        // A registration is usually the cheaper of the two, and always the
        // wrong price for a renewal.
        $quote = app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('mismatch.test'),
            DomainOperationKind::Register,
        );

        $this->expectException(DomainRefusedException::class);

        app(RedeemQuote::class)->execute(
            $this->customer,
            (string) $quote->getKey(),
            DomainOperationKind::Renew,
        );
    }

    #[Test]
    public function a_namespace_this_platform_does_not_sell_is_refused(): void
    {
        $this->tld->update(['enabled' => false]);

        $this->expectException(DomainRefusedException::class);

        app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('notsold.test'),
            DomainOperationKind::Register,
        );
    }

    #[Test]
    public function a_redemption_price_this_platform_was_never_told_is_not_guessed(): void
    {
        $this->tld->update(['redemption_price_minor' => null, 'redemption_cost_minor' => null]);

        // Quoting a guess would be quoting a registry penalty somebody has to
        // pay.
        $this->expectException(DomainRefusedException::class);

        app(QuoteDomain::class)->execute(
            $this->customer,
            $this->parse('unknown.test'),
            DomainOperationKind::Redeem,
        );
    }
}
