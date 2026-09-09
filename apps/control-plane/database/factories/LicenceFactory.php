<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * @extends Factory<Licence>
 */
class LicenceFactory extends Factory
{
    protected $model = Licence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product' => 'cpanel',
            'licence_type' => 'admin',
            'environment' => DeploymentEnvironment::Staging,
            'state' => LicenceState::Active,
            'starts_on' => CarbonImmutable::now()->subMonth()->toDateString(),
            'expires_on' => CarbonImmutable::now()->addYear()->toDateString(),
            'seats' => 100,
        ];
    }

    public function in(LicenceState $state): self
    {
        return $this->state(fn (): array => ['state' => $state]);
    }

    public function forEnvironment(DeploymentEnvironment $environment): self
    {
        return $this->state(fn (): array => ['environment' => $environment]);
    }

    public function expiringOn(string $date): self
    {
        return $this->state(fn (): array => ['expires_on' => $date]);
    }
}
