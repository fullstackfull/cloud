<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Application\Actions\ReleaseQuarantinedAddresses;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Application\Queries\CustomerIpAssignments;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Enums\ReverseDnsStatus;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\ReverseDnsRecord;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Ipam\Concerns\CreatesIpamFixtures;
use Tests\TestCase;

/**
 * F-12: a dedicated server's addresses come back when the machine does.
 *
 * Before this, decommissioning a dedicated service took the machine off the
 * customer and left every IP assignment on it live. One public IPv4 leaked per
 * lifecycle, and the departing customer kept a live assignment — which is
 * what the PTR endpoint checks — so they could go on publishing reverse DNS on
 * an address the platform would sooner or later hand to somebody else.
 *
 * The address does not go straight onto the sweeper's clock either. Until the
 * disks are erased the machine in the rack still has it configured, so it is
 * *held*: quarantined with no expiry, which the sweeper never ends. The clock
 * starts when an operator says the machine is empty — by putting it back on
 * the shelf, or by retiring it.
 */
final class DecommissioningGivesTheAddressBackTest extends TestCase
{
    use CreatesIpamFixtures, RefreshDatabase;

    private Customer $customer;

    private User $member;

    private Service $service;

    private DedicatedServer $server;

    private IpPool $pool;

    private Subnet $subnet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Queue::fake();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->member = User::factory()->create();
        $this->customer->members()->create([
            'user_id' => $this->member->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $this->service = Service::factory()->status(ServiceStatus::Suspended)->create([
            'customer_id' => $this->customer->getKey(),
            'kind' => 'dedicated',
            'suspended_at' => now()->subDays(31),
        ]);

        $this->server = $this->chassisFor($this->service, 'SN-F12-0001');

        $this->pool = IpPool::factory()->quarantineDays(7)->create(['slug' => 'f12-public-v4']);
        $this->subnet = Subnet::factory()->for($this->pool)->forBlock('203.0.113.0/28', gateway: '203.0.113.1')->create();
        app(SeedSubnetAddresses::class)->execute($this->subnet);
    }

    #[Test]
    public function decommissioning_releases_every_address_the_machine_was_wearing(): void
    {
        $assignments = $this->wear($this->server, 2);

        $this->terminate()->assertStatus(202);

        foreach ($assignments as $assignment) {
            $assignment->refresh();
            $this->assertNotNull($assignment->released_at, 'An assignment outlived the machine leaving its customer.');

            $address = IpAddress::query()->findOrFail($assignment->ip_address_id);
            $this->assertSame(IpAddressStatus::Quarantined, $address->status);
            $this->assertSame(ReleaseReason::ServiceTerminated, $address->quarantine_reason);
            // Held, not on a clock: the machine in the rack still carries it.
            $this->assertNull($address->quarantined_until);
        }

        $this->assertSame(0, CustomerIpAssignments::of($this->customer)->count());
    }

    #[Test]
    public function the_departing_customer_loses_control_of_the_ptr(): void
    {
        [$assignment] = $this->wear($this->server, 1);

        ReverseDnsRecord::query()->create([
            'ip_address_id' => $assignment->ip_address_id,
            'hostname' => 'mail.departing.example',
            'status' => ReverseDnsStatus::Active,
        ]);

        $this->terminate()->assertStatus(202);

        $this->assertSame(
            ReverseDnsStatus::Removing,
            ReverseDnsRecord::query()->where('ip_address_id', $assignment->ip_address_id)->sole()->status,
            'The departing customer’s PTR is still published on an address the platform will hand on.',
        );

        $this->actingAs($this->member)
            ->putJson('/api/v1/ips/'.$assignment->getKey().'/rdns', ['hostname' => 'still.mine.example'])
            ->assertNotFound();
    }

    #[Test]
    public function a_held_address_is_not_handed_on_while_the_machine_still_carries_it(): void
    {
        [$assignment] = $this->wear($this->server, 1);

        $this->terminate()->assertStatus(202);

        $this->travel(60)->days();

        $this->assertSame(0, app(ReleaseQuarantinedAddresses::class)->execute());
        $this->assertSame(
            IpAddressStatus::Quarantined,
            IpAddress::query()->findOrFail($assignment->ip_address_id)->status,
        );
    }

    #[Test]
    public function only_the_decommissioned_chassis_gives_its_addresses_back(): void
    {
        /*
         * The release is keyed by the machine, not by the service. A service
         * with two chassis loses one per decommission (the action takes the
         * first), and the other chassis is still racked, still assigned, and
         * still answering on its own addresses.
         */
        $second = $this->chassisFor($this->service, 'SN-F12-0002');

        $first = $this->wear($this->server, 1);
        $other = $this->wear($second, 1);

        $this->terminate()->assertStatus(202);

        $decommissionedId = (string) DedicatedServer::query()
            ->where('status', DedicatedServerStatus::Maintenance)
            ->sole()
            ->getKey();

        $byChassis = [
            (string) $this->server->getKey() => $first[0],
            (string) $second->getKey() => $other[0],
        ];

        foreach ($byChassis as $chassisId => $assignment) {
            $assignment->refresh();

            if ($chassisId === $decommissionedId) {
                $this->assertNotNull($assignment->released_at);
            } else {
                $this->assertNull($assignment->released_at, 'A chassis nobody decommissioned lost its address.');
            }
        }
    }

    #[Test]
    public function returning_the_machine_to_stock_starts_the_clock(): void
    {
        [$assignment] = $this->wear($this->server, 1);
        $this->terminate()->assertStatus(202);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/dedicated/'.$this->server->getKey().'/return-to-stock', [
                'evidence' => 'Disks wiped with a three-pass erase, verified from the console.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', DedicatedServerStatus::Available->value);

        $address = IpAddress::query()->findOrFail($assignment->ip_address_id);
        $this->assertSame(IpAddressStatus::Quarantined, $address->status);
        $this->assertNotNull($address->quarantined_until, 'The machine is empty and its address is still held.');
        $this->assertSame(now()->addDays(7)->toDateString(), $address->quarantined_until->toDateString());

        $this->travel(8)->days();

        $this->assertSame(1, app(ReleaseQuarantinedAddresses::class)->execute());
        $this->assertSame(
            IpAddressStatus::Available,
            IpAddress::query()->findOrFail($assignment->ip_address_id)->status,
        );
    }

    #[Test]
    public function retiring_a_machine_starts_the_clock_and_records_whose_word_it_is(): void
    {
        [$assignment] = $this->wear($this->server, 1);
        $this->terminate()->assertStatus(202);

        // Found faulty in the rack after it came back.
        $this->server->refresh()->forceFill(['status' => DedicatedServerStatus::Failed])->save();

        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson('/api/admin/dedicated/'.$this->server->getKey().'/retire', [
                'evidence' => 'Board failed; chassis sent for disposal, disks shredded on site.',
            ])
            ->assertOk()
            ->assertJsonPath('data.dedicated_server_id', (string) $this->server->getKey())
            ->assertJsonPath('data.status', DedicatedServerStatus::Retired->value);

        $this->assertSame(DedicatedServerStatus::Retired, $this->server->refresh()->status);

        $address = IpAddress::query()->findOrFail($assignment->ip_address_id);
        $this->assertSame(now()->addDays(7)->toDateString(), $address->quarantined_until?->toDateString());

        $entry = AuditEntry::query()->where('action', AuditAction::DedicatedServerRetired)->sole();

        $this->assertSame('SN-F12-0001', $entry->context['serial'] ?? null);
        $this->assertStringContainsString('shredded', (string) ($entry->context['evidence'] ?? ''));
        // The whole value of the row is whose word it is.
        $this->assertSame(
            sprintf('%s <%s>', $operator->name, $operator->email),
            $entry->context['retired_by'] ?? null,
        );
        $this->assertTrue($entry->action->isAnAssertionAboutTheWorld());
    }

    #[Test]
    public function a_scrapped_machine_is_not_put_back_on_the_shelf_by_the_other_order(): void
    {
        /*
         * Shelve then retire passes: a machine on the shelf may still be sent
         * for disposal. Retire then shelve is refused, because `retired` has no
         * outward edges — and that absence is the only thing standing between
         * a chassis sent for disposal and the next customer's order.
         */
        $this->terminate()->assertStatus(202);
        $operator = $this->operator();

        $this->actingAs($operator)
            ->postJson('/api/admin/dedicated/'.$this->server->getKey().'/retire', ['evidence' => 'Sent for disposal.'])
            ->assertOk();

        $this->actingAs($operator)
            ->postJson('/api/admin/dedicated/'.$this->server->getKey().'/return-to-stock', ['evidence' => 'Wiped.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state.illegal_transition');

        $this->assertSame(DedicatedServerStatus::Retired, $this->server->refresh()->status);

        $other = $this->chassisFor(null, 'SN-F12-0003', DedicatedServerStatus::Maintenance);

        $this->actingAs($operator)
            ->postJson('/api/admin/dedicated/'.$other->getKey().'/return-to-stock', ['evidence' => 'Wiped.'])
            ->assertOk();

        $this->actingAs($operator)
            ->postJson('/api/admin/dedicated/'.$other->getKey().'/retire', ['evidence' => 'Sent for disposal.'])
            ->assertOk()
            ->assertJsonPath('data.status', DedicatedServerStatus::Retired->value);
    }

    #[Test]
    public function a_machine_still_assigned_cannot_be_retired(): void
    {
        $this->actingAs($this->operator())
            ->postJson('/api/admin/dedicated/'.$this->server->getKey().'/retire', ['evidence' => 'Looks dead.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'dedicated.still_assigned');

        $this->assertSame(DedicatedServerStatus::Active, $this->server->refresh()->status);
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::DedicatedServerRetired)->count());
    }

    #[Test]
    public function retiring_needs_evidence_and_the_permission_to_manage_hardware(): void
    {
        $this->terminate()->assertStatus(202);

        $this->actingAs($this->operator())
            ->postJson('/api/admin/dedicated/'.$this->server->getKey().'/retire', [])
            ->assertStatus(422);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/admin/dedicated/'.$this->server->getKey().'/retire', ['evidence' => 'Sent for disposal.'])
            ->assertForbidden();

        $this->assertSame(DedicatedServerStatus::Maintenance, $this->server->refresh()->status);
    }

    private function terminate(): TestResponse
    {
        return $this->actingAs($this->operator())
            ->deleteJson('/api/admin/services/'.$this->service->getKey(), [
                'reason' => 'Cancelled and unpaid, ticket 8891.',
            ]);
    }

    private function chassisFor(
        ?Service $service,
        string $serial,
        DedicatedServerStatus $status = DedicatedServerStatus::Active,
    ): DedicatedServer {
        return DedicatedServer::factory()->inDatacenter(Datacenter::factory()->create())->create([
            'customer_id' => $service?->customer_id,
            'service_id' => $service?->getKey(),
            'status' => $status,
            'serial' => $serial,
        ]);
    }

    /**
     * Addresses put on a machine the way the provisioning handler puts them
     * there: reserved for a job, then committed against the chassis.
     *
     * @return list<IpAssignment>
     */
    private function wear(DedicatedServer $server, int $count): array
    {
        $allocator = app(IpAllocator::class);
        $job = $this->createProvisioningJob('running', (string) $this->customer->getKey());

        $assignments = [];

        foreach ($allocator->reserve($this->pool, $job, $this->customer, $count) as $reservation) {
            $assignments[] = $allocator->commit(
                reservation: $reservation,
                serviceId: (string) $server->service_id,
                macAddress: '52:54:00:12:34:'.sprintf('%02d', count($assignments) + 10),
                assignable: $server,
            );
        }

        return $assignments;
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }
}
