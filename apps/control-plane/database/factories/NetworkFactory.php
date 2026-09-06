<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Ipam\Domain\Enums\NetworkPurpose;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;

/**
 * @extends Factory<Network>
 */
class NetworkFactory extends Factory
{
    protected $model = Network::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'datacenter_id' => fn (): string => IpPoolFactory::datacenterId(),
            'slug' => 'net-'.Str::lower(Str::random(8)),
            'name' => 'Customer Public',
            'purpose' => NetworkPurpose::Public,
            'vlan_id' => fake()->numberBetween(100, 4000),
            'bridge' => 'vmbr0',
            'is_customer_facing' => true,
            'is_active' => true,
        ];
    }

    public function management(): static
    {
        return $this->state(fn (): array => [
            'purpose' => NetworkPurpose::Management,
            'name' => 'Hypervisor Management',
            'is_customer_facing' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
