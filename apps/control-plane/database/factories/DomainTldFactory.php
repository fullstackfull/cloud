<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainTld;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;

/**
 * @extends Factory<DomainTld>
 */
class DomainTldFactory extends Factory
{
    protected $model = DomainTld::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tld' => Str::lower(Str::random(6)),
            // Disabled by default, because "on sale" is a commercial decision
            // and a factory that made it silently would let a test prove a
            // customer can buy something nobody decided to sell.
            'enabled' => false,
            'provider' => FakeDomainRegistrarProvider::NAME,
            'allows_registration' => true,
            'allows_transfer' => true,
            'allows_renewal' => true,
            'supports_premium' => true,
            'minimum_term_years' => 1,
            'maximum_term_years' => 10,
            'currency' => 'KWD',
            // A renewal dearer than a registration, which is the shape of the
            // whole industry and the shape a test should default to.
            'registration_price_minor' => 3_500,
            'renewal_price_minor' => 4_500,
            'transfer_price_minor' => 4_000,
            'redemption_price_minor' => 30_000,
            'registration_cost_minor' => 2_800,
            'renewal_cost_minor' => 3_600,
            'transfer_cost_minor' => 3_200,
            'redemption_cost_minor' => 25_000,
            'grace_days' => 30,
            'redemption_days' => 30,
        ];
    }

    public function onSale(): self
    {
        return $this->state(fn (): array => ['enabled' => true]);
    }

    public function named(string $tld): self
    {
        return $this->state(fn (): array => ['tld' => $tld]);
    }

    /**
     * A namespace whose registry lifecycle this platform has not been told.
     *
     * The `.sy` shape: on sale in principle, and with nothing invented about
     * what happens after it expires.
     */
    public function withUnknownLifecycle(): self
    {
        return $this->state(fn (): array => [
            'grace_days' => null,
            'redemption_days' => null,
            'redemption_price_minor' => null,
            'redemption_cost_minor' => null,
        ]);
    }
}
