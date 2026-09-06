<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;

/**
 * @extends Factory<ReverseDnsRecord>
 */
class ReverseDnsRecordFactory extends Factory
{
    protected $model = ReverseDnsRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ip_address_id' => IpAddress::factory(),
            'hostname' => fake()->domainWord().'.lynomia.test',
            'status' => ReverseDnsStatus::Pending,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => ReverseDnsStatus::Active]);
    }
}
