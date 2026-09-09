<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Providers\Domain\Enums\ConnectionState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;

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
            'environment' => DeploymentEnvironment::Staging,
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
        return $this->state(fn (): array => ['environment' => DeploymentEnvironment::Production]);
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
