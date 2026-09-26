<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Exceptions\IpPoolExhaustedException;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A block an operator registers is a block the allocator can hand out (F-02).
 *
 * The operator's subnet route used to write the subnet row and nothing else.
 * The allocator hands out `ip_addresses` rows, and the only thing in `src/`
 * that wrote any was the reference-topology loader, which refuses to run in
 * production. So a /24 registered through the Control Center was an empty
 * address space: `IpAllocator::reserve()` answered "0 allocatable address(es)
 * left", CreateVpsHandler and ProvisionDedicatedHandler classified that as
 * capacity, and every VPS and Dedicated build on an operator-built estate
 * waited for an address that could never exist.
 *
 * Every row here goes through the route an operator uses, and every "can it be
 * allocated" is asked of the allocator itself — never of a count of rows,
 * which is the question the old tests asked and the one that could not fail.
 */
final class ARegisteredSubnetHandsOutAddressesTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Datacenter $datacenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->syncRoles([Role::InfrastructureAdmin->value]);

        $this->datacenter = Datacenter::factory()->create(['slug' => 'kw-north']);
    }

    #[Test]
    public function a_block_registered_through_the_operator_route_can_be_allocated_to_a_customer(): void
    {
        $pool = $this->pool();

        $response = $this->register($pool, ['cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1'])
            ->assertCreated()
            // Said in the answer, so the operator knows at once what they got.
            ->assertJsonPath('data.allocatable_addresses', 253);

        $this->assertSame('203.0.113.0/24', $response->json('data.cidr'));

        $reservations = app(IpAllocator::class)->reserve(
            scope: $pool,
            provisioningJobId: $this->job(),
            customer: Customer::factory()->create(),
            count: 1,
        );

        $this->assertCount(1, $reservations);

        $given = $reservations[0]->ipAddress()->firstOrFail()->address;

        $this->assertNotContains(
            $given,
            ['203.0.113.0', '203.0.113.1', '203.0.113.255'],
            'The allocator handed a customer the network, gateway or broadcast address.',
        );
    }

    #[Test]
    public function the_network_broadcast_and_gateway_exist_and_are_never_available(): void
    {
        $this->register($this->pool(), ['cidr' => '203.0.113.0/24', 'gateway' => '203.0.113.1'])->assertCreated();

        foreach (['203.0.113.0', '203.0.113.1', '203.0.113.255'] as $address) {
            $this->assertSame(
                IpAddressStatus::Unavailable,
                IpAddress::query()->where('address', $address)->sole()->status,
                $address.' was written allocatable.',
            );
        }

        $this->assertSame(256, IpAddress::query()->count());
        $this->assertSame(253, IpAddress::query()->available()->count());
    }

    #[Test]
    public function addresses_the_operator_reserves_at_registration_are_never_handed_out(): void
    {
        $pool = $this->pool();

        // A /29: .0 network, .7 broadcast, .1 gateway, and two the operator
        // keeps for a router pair. Three are left, and exactly three.
        $this->register($pool, [
            'cidr' => '198.51.100.0/29',
            'gateway' => '198.51.100.1',
            'reserved_addresses' => ['198.51.100.2', '198.51.100.3'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.allocatable_addresses', 3);

        $customer = Customer::factory()->create();
        $allocator = app(IpAllocator::class);

        $given = array_map(
            static fn ($reservation): string => $reservation->ipAddress()->firstOrFail()->address,
            $allocator->reserve($pool, $this->job(), $customer, 3),
        );

        sort($given);
        $this->assertSame(['198.51.100.4', '198.51.100.5', '198.51.100.6'], $given);

        $this->expectException(IpPoolExhaustedException::class);
        $allocator->reserve($pool, $this->job(), $customer, 1);
    }

    #[Test]
    public function a_reserved_address_outside_the_block_is_refused_and_nothing_is_written(): void
    {
        $this->register($this->pool(), [
            'cidr' => '198.51.100.0/29',
            'reserved_addresses' => ['198.51.100.9'],
        ])->assertStatus(422);

        $this->assertSame(0, Subnet::query()->count());
        $this->assertSame(0, IpAddress::query()->count());
    }

    #[Test]
    public function a_refused_overlap_writes_no_address_rows(): void
    {
        $pool = $this->pool();

        $this->register($pool, ['cidr' => '203.0.113.0/24'])->assertCreated();
        $before = IpAddress::query()->count();

        $this->register($this->pool(), ['cidr' => '203.0.113.0/25'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'infrastructure.subnet_overlaps');

        $this->assertSame($before, IpAddress::query()->count());
    }

    #[Test]
    public function a_block_too_wide_to_expand_is_refused_unless_it_is_registered_as_held_space(): void
    {
        $pool = $this->pool();

        $this->register($pool, ['cidr' => '10.0.0.0/15'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'infrastructure.subnet_too_wide_to_allocate_from');

        $this->assertSame(0, Subnet::query()->count());

        // Registered to hold the space against overlaps, and said to be that.
        $this->register($pool, ['cidr' => '10.0.0.0/15', 'allocatable' => false])
            ->assertCreated()
            ->assertJsonPath('data.allocatable_addresses', 0);

        $this->assertSame(0, IpAddress::query()->count());
    }

    #[Test]
    public function a_v6_block_is_registered_without_address_rows(): void
    {
        $pool = $this->pool(version: IpVersion::V6);

        $this->register($pool, ['cidr' => '2001:db8::/48'])
            ->assertCreated()
            ->assertJsonPath('data.allocatable_addresses', 0);

        $this->assertSame(0, IpAddress::query()->count());
    }

    #[Test]
    public function the_audit_entry_says_how_many_addresses_became_allocatable(): void
    {
        $this->register($this->pool(), [
            'cidr' => '198.51.100.0/29',
            'gateway' => '198.51.100.1',
            'reserved_addresses' => ['198.51.100.2'],
        ])->assertCreated();

        $entry = AuditEntry::query()->where('action', AuditAction::SubnetRegistered)->sole();

        $this->assertSame(4, $entry->context['allocatable_addresses'] ?? null);
        $this->assertSame(['198.51.100.2'], $entry->context['reserved_addresses'] ?? null);
    }

    private function job(): string
    {
        return (string) ProvisioningJob::factory()->create()->getKey();
    }

    private function pool(IpVersion $version = IpVersion::V4): IpPool
    {
        return IpPool::factory()->create([
            'datacenter_id' => $this->datacenter->getKey(),
            'scope' => IpPoolScope::Public,
            'ip_version' => $version,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function register(IpPool $pool, array $body): TestResponse
    {
        // The segment the block is on: a customer block names one.
        $body['network_id'] ??= Network::factory()->create(['datacenter_id' => $this->datacenter->getKey()])->getKey();

        return $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/ip-pools/'.$pool->getKey().'/subnets', $body);
    }
}
