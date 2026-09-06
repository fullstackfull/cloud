<?php

declare(strict_types=1);

namespace Database\Factories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Catalog\Infrastructure\Models\TaxRule;

/**
 * @extends Factory<TaxRule>
 */
class TaxRuleFactory extends Factory
{
    protected $model = TaxRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Kuwait VAT',
            'country' => 'KW',
            'state' => null,
            // Exact decimal strings throughout: the column is numeric(9,6) and
            // the value never passes through a float on its way in or out.
            'rate' => '0.150000',
            'is_inclusive' => false,
            'is_active' => true,
            'effective_from' => now()->subYear(),
        ];
    }

    public function jurisdiction(string $country, ?string $state = null): static
    {
        return $this->state(fn (): array => [
            'country' => strtoupper($country),
            'state' => $state,
        ]);
    }

    public function rate(string $rate): static
    {
        return $this->state(fn (): array => ['rate' => $rate]);
    }

    public function inclusive(): static
    {
        return $this->state(fn (): array => ['is_inclusive' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function effective(DateTimeInterface $from, ?DateTimeInterface $until = null): static
    {
        return $this->state(fn (): array => [
            'effective_from' => $from,
            'effective_until' => $until,
        ]);
    }
}
