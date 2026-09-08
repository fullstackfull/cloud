<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord as DnsRecordValue;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;

/**
 * @extends Factory<DnsRecord>
 */
class DnsRecordFactory extends Factory
{
    protected $model = DnsRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dns_zone_id' => DnsZone::factory(),
            'type' => DnsRecordType::A,
            'name' => Str::lower(Str::random(6)).'.example.test',
            // TEST-NET-3, reserved for documentation: a fixture that pointed a
            // record at a real address would be a fixture somebody could
            // accidentally publish.
            'content' => '203.0.113.10',
            'ttl' => DnsRecordValue::AUTOMATIC_TTL,
            'state' => DnsState::Pending,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'state' => DnsState::Active,
            'provider_record_id' => 'record-'.Str::random(6),
            'last_published_at' => now(),
        ]);
    }

    public function in(DnsZone $zone): self
    {
        return $this->state(fn (): array => [
            'dns_zone_id' => $zone->getKey(),
            'name' => 'www.'.$zone->name,
        ]);
    }
}
