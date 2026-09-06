<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;

/**
 * @extends Factory<IpAssignment>
 */
class IpAssignmentFactory extends Factory
{
    protected $model = IpAssignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ip_address_id' => IpAddress::factory()->assigned(),
            'customer_id' => null,
            'service_id' => null,
            'is_primary' => true,
            'mac_address' => strtoupper(fake()->macAddress()),
            'assigned_at' => now(),
        ];
    }

    public function assignedAt(mixed $when): static
    {
        return $this->state(fn (): array => ['assigned_at' => $when]);
    }

    public function released(): static
    {
        return $this->state(fn (): array => ['released_at' => now()]);
    }
}
