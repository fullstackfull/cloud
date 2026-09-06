<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;

/**
 * @extends Factory<IpPool>
 */
class IpPoolFactory extends Factory
{
    protected $model = IpPool::class;

    /**
     * A region and datacenter to hang infrastructure off.
     *
     * Inserted with the query builder rather than through a model factory
     * because the inventory module owns those tables; IPAM's tests need a
     * valid foreign key, not a dependency on another module's classes.
     */
    public static function datacenterId(): string
    {
        $regionId = (string) Str::ulid();
        $datacenterId = (string) Str::ulid();
        $suffix = Str::lower(Str::random(8));

        DB::table('regions')->insert([
            'id' => $regionId,
            'slug' => 'kw-'.$suffix,
            'name' => json_encode(['en' => 'Kuwait', 'ar' => 'الكويت'], JSON_THROW_ON_ERROR),
            'country' => 'KW',
            'is_active' => true,
            'accepts_new_services' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('datacenters')->insert([
            'id' => $datacenterId,
            'region_id' => $regionId,
            'slug' => 'kw-dc-'.$suffix,
            'name' => 'Kuwait DC',
            'facility' => 'Zajil',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $datacenterId;
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'datacenter_id' => fn (): string => self::datacenterId(),
            'slug' => 'pool-'.Str::lower(Str::random(8)),
            'name' => 'Public IPv4 Pool',
            'ip_version' => IpVersion::V4,
            'scope' => IpPoolScope::Public,
            'is_active' => true,
            'quarantine_days' => 7,
        ];
    }

    public function quarantineDays(int $days): static
    {
        return $this->state(fn (): array => ['quarantine_days' => $days]);
    }

    public function private(): static
    {
        return $this->state(fn (): array => [
            'scope' => IpPoolScope::Private,
            'name' => 'Private RFC1918 Pool',
            // Private space carries no external reputation, so it recycles in
            // a day rather than a week.
            'quarantine_days' => 1,
        ]);
    }

    /**
     * Addresses that reach the hypervisor and BMC control planes. They are
     * never assigned to a customer service, which is what
     * IpPoolScope::isCustomerAllocatable() records and the allocator enforces.
     */
    public function management(): static
    {
        return $this->state(fn (): array => [
            'scope' => IpPoolScope::Management,
            'name' => 'Management Pool',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
