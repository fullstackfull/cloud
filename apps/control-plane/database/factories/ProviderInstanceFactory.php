<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Estate\Domain\Enums\ConnectionState;
use Lynomia\Modules\Estate\Domain\Enums\EstateEnvironment;
use Lynomia\Modules\Estate\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Estate\Domain\Enums\ProviderState;
use Lynomia\Modules\Estate\Domain\Enums\ReadinessState;
use Lynomia\Modules\Estate\Infrastructure\Models\ProviderInstance;

/**
 * @extends Factory<ProviderInstance>
 */
class ProviderInstanceFactory extends Factory
{
    protected $model = ProviderInstance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'provider-'.Str::lower(Str::random(6)),
            'category' => ProviderCategory::Dns,
            'driver' => 'fake',
            'environment' => EstateEnvironment::Staging,
            'state' => ProviderState::Draft,
            'endpoint' => 'fake://connected',
            'connection_state' => ConnectionState::NotTested,
            'readiness' => ReadinessState::NotReady,
        ];
    }

    public function of(ProviderCategory $category): self
    {
        return $this->state(fn (): array => ['category' => $category]);
    }

    public function reachableAs(string $marker): self
    {
        return $this->state(fn (): array => ['endpoint' => 'fake://'.$marker]);
    }

    public function inProduction(): self
    {
        return $this->state(fn (): array => ['environment' => EstateEnvironment::Production]);
    }

    public function enabled(): self
    {
        return $this->state(fn (): array => [
            'state' => ProviderState::Enabled,
            'connection_state' => ConnectionState::Connected,
            'readiness' => ReadinessState::ReadyForProduction,
        ]);
    }
}
