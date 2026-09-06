<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'kind' => 'vps',
            'status' => ServiceStatus::Pending,
            'label' => 'vps-'.Str::lower(Str::random(6)),
            // Snapshotted from the plan at purchase, so a later plan edit
            // cannot silently resize a machine someone is running.
            'resources' => ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 80],
        ];
    }

    public function status(ServiceStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function provisioning(): static
    {
        return $this->state(fn (): array => ['status' => ServiceStatus::Provisioning]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => ServiceStatus::Active,
            'activated_at' => now(),
        ]);
    }
}
