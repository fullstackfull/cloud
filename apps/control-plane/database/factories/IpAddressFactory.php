<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;

/**
 * @extends Factory<IpAddress>
 */
class IpAddressFactory extends Factory
{
    protected $model = IpAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subnet_id' => Subnet::factory(),
            'address' => fake()->unique()->ipv4(),
            'ip_version' => IpVersion::V4,
            'status' => IpAddressStatus::Available,
        ];
    }

    public function reserved(): static
    {
        return $this->state(fn (): array => ['status' => IpAddressStatus::Reserved]);
    }

    public function assigned(): static
    {
        return $this->state(fn (): array => ['status' => IpAddressStatus::Assigned]);
    }

    public function unavailable(): static
    {
        return $this->state(fn (): array => ['status' => IpAddressStatus::Unavailable]);
    }

    public function quarantinedUntil(mixed $until, ReleaseReason $reason = ReleaseReason::ServiceTerminated): static
    {
        return $this->state(fn (): array => [
            'status' => IpAddressStatus::Quarantined,
            'quarantined_until' => $until,
            'quarantine_reason' => $reason,
        ]);
    }
}
