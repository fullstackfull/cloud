<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * @extends Factory<DnsZone>
 */
class DnsZoneFactory extends Factory
{
    protected $model = DnsZone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            // `.test` is reserved by the RFCs for exactly this, so a fixture
            // can never accidentally name a domain somebody owns.
            'name' => Str::lower(Str::random(10)).'.test',
            // Claimed and nothing more. A factory whose default is "active"
            // makes it easy to write a test that never exercises the part that
            // matters, which is how a zone gets there.
            'state' => DnsState::Pending,
            'provider' => 'fake',
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'state' => DnsState::Active,
            'provider_zone_id' => 'zone-'.Str::random(6),
            'nameservers' => ['a.ns.fake.test', 'b.ns.fake.test'],
            'last_synced_at' => now(),
        ]);
    }

    public function heldBy(Customer $customer): self
    {
        return $this->state(fn (): array => ['customer_id' => $customer->getKey()]);
    }
}
