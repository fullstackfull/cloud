<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;

/**
 * @extends Factory<IpReservation>
 */
class IpReservationFactory extends Factory
{
    protected $model = IpReservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ip_address_id' => IpAddress::factory()->reserved(),
            'provisioning_job_id' => null,
            'customer_id' => null,
            'expires_at' => now()->addHour(),
        ];
    }

    public function expiredAt(mixed $when): static
    {
        return $this->state(fn (): array => ['expires_at' => $when]);
    }

    public function released(ReleaseReason $reason = ReleaseReason::OperatorAction): static
    {
        return $this->state(fn (): array => [
            'released_at' => now(),
            'released_reason' => $reason,
        ]);
    }
}
