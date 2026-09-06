<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;

/**
 * @extends Factory<ResourceDrift>
 */
class ResourceDriftFactory extends Factory
{
    protected $model = ResourceDrift::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'fake',
            'resource_type' => 'virtual_machine',
            'provider_reference' => 'vm-'.Str::lower(Str::random(8)),
            'kind' => DriftKind::OrphanAtProvider,
            'severity' => DriftSeverity::Warning,
            'status' => DriftStatus::Open,
            'expected' => null,
            'observed' => ['power_state' => 'running'],
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => DriftStatus::Resolved,
            'resolution' => 'adopted',
            'resolved_at' => now(),
        ]);
    }
}
