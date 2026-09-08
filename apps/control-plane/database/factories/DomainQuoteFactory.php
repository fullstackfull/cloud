<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Domains\Domain\Enums\DomainOperationKind;
use Lynomia\Modules\Domains\Infrastructure\Models\DomainQuote;
use Lynomia\Modules\Domains\Infrastructure\Providers\FakeDomainRegistrarProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * @extends Factory<DomainQuote>
 */
class DomainQuoteFactory extends Factory
{
    protected $model = DomainQuote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => Str::lower(Str::random(10)).'.test',
            'tld' => 'test',
            'operation' => DomainOperationKind::Register,
            'term_years' => 1,
            'premium' => false,
            'currency' => 'KWD',
            'price_minor' => 3_500,
            'cost_minor' => 2_800,
            'provider' => FakeDomainRegistrarProvider::NAME,
            'expires_at' => CarbonImmutable::now()->addMinutes(15),
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);
    }

    public function spent(): self
    {
        return $this->state(fn (): array => ['consumed_at' => CarbonImmutable::now()]);
    }

    public function premium(int $priceMinor = 250_000): self
    {
        return $this->state(fn (): array => [
            'premium' => true,
            'price_minor' => $priceMinor,
            'provider_reference' => 'fake-premium-quote-'.Str::lower(Str::random(12)),
        ]);
    }
}
