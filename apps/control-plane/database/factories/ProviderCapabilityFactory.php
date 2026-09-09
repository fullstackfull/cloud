<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;

/**
 * @extends Factory<ProviderCapability>
 */
class ProviderCapabilityFactory extends Factory
{
    protected $model = ProviderCapability::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'capability' => 'records',
            'state' => CapabilityState::Supported,
            'observed_at' => CarbonImmutable::now(),
        ];
    }

    public function named(string $capability): self
    {
        return $this->state(fn (): array => ['capability' => $capability]);
    }

    public function in(CapabilityState $state): self
    {
        return $this->state(fn (): array => ['state' => $state]);
    }
}
