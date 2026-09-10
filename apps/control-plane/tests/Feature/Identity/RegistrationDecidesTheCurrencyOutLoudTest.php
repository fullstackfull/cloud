<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A customer's currency is decided by the country they chose, from a table a
 * human wrote, and it is never a default nobody mentioned.
 *
 * The defect these tests close: registration accepted no country at all and
 * booked the account in the platform's own currency. A customer in Riyadh
 * discovered they were being billed in Kuwaiti dinars when their first invoice
 * arrived, and changing it afterwards is a support conversation and a
 * workflow.
 */
final class RegistrationDecidesTheCurrencyOutLoudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amal Al-Sabah',
            'email' => 'amal@example.com',
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
            'country' => 'KW',
            'accepts_terms' => true,
        ], $overrides);
    }

    #[Test]
    public function the_options_endpoint_offers_the_countries_and_the_currencies_the_platform_bills_in(): void
    {
        $response = $this->getJson(route('api.v1.registration.options'))->assertOk();

        $countries = (array) $response->json('data.countries');
        $currencies = (array) $response->json('data.currencies');

        // Bounded, and the standard's list rather than a sample of it.
        $this->assertGreaterThan(200, count($countries));
        $this->assertLessThan(300, count($countries));
        $this->assertSame(count($countries), $response->json('meta.countries_count'));

        $codes = array_column($countries, 'code');
        foreach (['KW', 'SA', 'AE', 'GB', 'US', 'JP'] as $expected) {
            $this->assertContains($expected, $codes);
        }

        // Every recommendation is a currency the platform actually bills in.
        foreach ($countries as $country) {
            $this->assertContains($country['currency'], $currencies, (string) $country['code']);
        }

        $this->assertContains('KWD', $currencies);
        $this->assertSame(
            'KWD',
            collect($countries)->firstWhere('code', 'KW')['currency'],
        );
        $this->assertSame(
            'SAR',
            collect($countries)->firstWhere('code', 'SA')['currency'],
        );

        // A country with no row of its own is told which currency it gets.
        $japan = collect($countries)->firstWhere('code', 'JP');
        $this->assertFalse($japan['currency_is_explicit']);
        $this->assertSame($response->json('data.fallback_currency'), $japan['currency']);
    }

    #[Test]
    public function the_options_endpoint_needs_no_account(): void
    {
        // It is read before an account exists, and it discloses nothing about
        // anybody: two lists and a mapping, all of them configuration.
        $this->getJson(route('api.v1.registration.options'))->assertOk();
        $this->assertGuest();
    }

    #[Test]
    public function a_registration_without_a_country_is_refused_rather_than_defaulted(): void
    {
        Notification::fake();

        $payload = $this->payload();
        unset($payload['country']);

        $this->postJson(route('api.v1.register'), $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['country']]]]);

        $this->assertSame(0, Customer::query()->count());
    }

    #[Test]
    public function a_country_the_standard_does_not_contain_is_refused(): void
    {
        $this->postJson(route('api.v1.register'), $this->payload(['country' => 'ZZ']))
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['country']]]]);

        $this->assertSame(0, Customer::query()->count());
    }

    #[Test]
    public function the_country_decides_the_currency(): void
    {
        Notification::fake();

        $this->postJson(route('api.v1.register'), $this->payload(['country' => 'SA']))
            ->assertAccepted();

        $customer = Customer::query()->sole();

        $this->assertSame('SA', $customer->country);
        $this->assertSame('SAR', $customer->currency);
    }

    #[Test]
    public function a_country_with_no_row_of_its_own_gets_the_configured_fallback_and_not_the_platform_default(): void
    {
        Notification::fake();

        $this->postJson(route('api.v1.register'), $this->payload(['country' => 'JP']))
            ->assertAccepted();

        $customer = Customer::query()->sole();

        $this->assertSame('JP', $customer->country);
        $this->assertSame(
            config('billing.country_currencies.*'),
            $customer->currency,
            'An unlisted country must take the explicit fallback, which the registration screen shows before submit.',
        );
        $this->assertNotSame('KWD', $customer->currency);
    }

    #[Test]
    public function a_customer_may_override_the_recommendation_with_any_currency_the_platform_bills_in(): void
    {
        Notification::fake();

        $this->postJson(route('api.v1.register'), $this->payload([
            'country' => 'SA',
            'currency' => 'usd',
        ]))->assertAccepted();

        $customer = Customer::query()->sole();

        $this->assertSame('SA', $customer->country);
        $this->assertSame('USD', $customer->currency);
    }

    #[Test]
    public function a_currency_the_platform_does_not_bill_in_is_refused_however_it_is_submitted(): void
    {
        Notification::fake();

        /*
         * The forgery this refuses: a browser that rewrites the currency
         * select's options in DevTools, or a script that posts straight to the
         * endpoint. Neither can book an account in a currency the payment
         * provider cannot take.
         */
        foreach (['JPY', 'BTC', 'XXX', 'kwdd'] as $forged) {
            $this->postJson(route('api.v1.register'), $this->payload(['currency' => $forged]))
                ->assertStatus(422)
                ->assertJsonStructure(['error' => ['details' => ['fields' => ['currency']]]]);
        }

        $this->assertSame(0, Customer::query()->count());
    }

    #[Test]
    public function every_configured_recommendation_is_a_currency_the_platform_bills_in(): void
    {
        /** @var array<string, string> $map */
        $map = config('billing.country_currencies');
        /** @var list<string> $enabled */
        $enabled = config('billing.currencies');

        foreach ($map as $country => $currency) {
            $this->assertContains(
                $currency,
                $enabled,
                "config/billing.php recommends {$currency} for {$country}, which the platform does not bill in.",
            );
        }

        $this->assertArrayHasKey('*', $map, 'The fallback row must exist, so no country falls through silently.');
    }
}
