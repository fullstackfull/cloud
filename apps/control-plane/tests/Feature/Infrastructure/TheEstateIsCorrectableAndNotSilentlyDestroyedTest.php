<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Correcting the estate, and the things that are not corrections.
 *
 * ---------------------------------------------------------------------------
 * Why there is no delete here
 * ---------------------------------------------------------------------------
 *
 * Every object in this inventory is pointed at by something a customer paid
 * for. A cluster holds machines; a pool holds the addresses those machines
 * answer on; a panel server holds accounts; a chassis is somebody's dedicated
 * server. Deleting one would either orphan those rows or cascade through them,
 * and both are ways to lose a customer's service with a single click on an
 * operator screen.
 *
 * So the lifecycle is the one the models already have: a region stops
 * accepting new services, a cluster stops accepting placement, a node drains,
 * a chassis retires. What exists keeps working; what is new goes elsewhere.
 * That is a decision about the domain, not a limitation — and it is why these
 * tests are mostly about what a deactivation refuses to do while something is
 * still living on the thing being deactivated.
 */
final class TheEstateIsCorrectableAndNotSilentlyDestroyedTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->syncRoles([Role::SuperAdmin->value]);
    }

    // ---- corrections -------------------------------------------------------

    #[Test]
    public function an_operator_corrects_a_region_and_the_change_is_recorded(): void
    {
        $region = Region::factory()->create(['slug' => 'kw-central', 'city' => 'Kuwait']);

        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/regions/'.$region->id, [
                'name' => ['en' => 'Kuwait Central', 'ar' => 'الكويت الوسطى'],
                'city' => 'Kuwait City',
                'accepts_new_services' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.accepts_new_services', false);

        $region->refresh();

        $this->assertSame('Kuwait City', $region->city);
        $this->assertSame('Kuwait Central', $region->nameFor('en'));
        // Winding down is not switching off: the customers already there keep
        // running, which is what the two flags are for.
        $this->assertTrue($region->is_active);
        $this->assertFalse($region->accepts_new_services);

        $entry = AuditEntry::query()->where('action', AuditAction::RegionUpdated)->sole();

        $this->assertSame($this->operator->id, $entry->actor_id);
        $this->assertArrayHasKey('before', (array) $entry->context);
        $this->assertArrayHasKey('after', (array) $entry->context);
    }

    #[Test]
    public function a_cluster_stops_accepting_placement_without_disturbing_what_runs_on_it(): void
    {
        $cluster = ComputeCluster::factory()->create(['status' => ClusterStatus::Active]);
        $node = ComputeNode::factory()->create(['cluster_id' => $cluster->id]);
        VirtualMachine::factory()->onNode($node)->create();

        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/clusters/'.$cluster->id, [
                'status' => ClusterStatus::Maintenance->value,
            ])
            ->assertOk();

        $cluster->refresh();

        $this->assertSame(ClusterStatus::Maintenance, $cluster->status);
        $this->assertFalse($cluster->acceptsPlacement());
        $this->assertSame(1, VirtualMachine::query()->count(), 'A maintenance window is not a deletion.');
    }

    // ---- the refusals ------------------------------------------------------

    #[Test]
    public function a_pool_with_addresses_in_use_cannot_be_switched_off(): void
    {
        $pool = IpPool::factory()->create(['is_active' => true]);
        $subnet = $pool->subnets()->create([
            'cidr' => '203.0.113.0/24',
            'ip_version' => 4,
            'prefix_length' => 24,
            'is_active' => true,
        ]);

        $subnet->addresses()->create([
            'address' => '203.0.113.10',
            'ip_version' => 4,
            'status' => 'assigned',
        ]);

        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/ip-pools/'.$pool->id, ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'infrastructure.still_in_use');

        $this->assertTrue($pool->fresh()?->is_active, 'Addresses in use kept answering from a pool marked inactive.');
    }

    #[Test]
    public function a_network_an_active_subnet_is_on_cannot_lose_its_bridge(): void
    {
        /*
         * F-07 (A×D), round four's re-audit: a VPS is built only onto a
         * subnet whose network has a bridge (Network::canCarryACustomerMachine()),
         * and clearing the bridge on a network an active subnet sits on made
         * the estate silently unbuildable - every build after it failed
         * ipam.pool_exhausted. It is refused while an active subnet is on the
         * network, as switching the network off is; a bridge can be renamed.
         */
        $network = Network::factory()->create(['bridge' => 'vmbr1']);
        $subnet = Subnet::factory()->create(['network_id' => $network->getKey()]);

        foreach ([null, ''] as $cleared) {
            $this->actingAs($this->operator)
                ->putJson('/api/admin/infrastructure/networks/'.$network->id, ['bridge' => $cleared])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'infrastructure.still_in_use');

            $this->assertSame('vmbr1', $network->fresh()?->bridge);
        }

        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/networks/'.$network->id, ['bridge' => 'vmbr2'])
            ->assertOk();

        $this->assertSame('vmbr2', $network->fresh()?->bridge);

        // With no active subnet on it any more, the bridge is the operator's.
        $subnet->forceFill(['is_active' => false])->save();

        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/networks/'.$network->id, ['bridge' => null])
            ->assertOk();

        $this->assertNull($network->fresh()?->bridge);
    }

    #[Test]
    public function a_panel_server_with_accounts_on_it_cannot_be_switched_off(): void
    {
        $node = HostingNode::factory()->create();
        HostingAccount::factory()->create(['hosting_node_id' => $node->id]);

        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/hosting-nodes/'.$node->id, ['status' => 'offline'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'infrastructure.still_in_use');

        // Draining is the supported way to wind one down: no new accounts, and
        // the ones that are there keep working.
        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/hosting-nodes/'.$node->id, ['accepts_new_accounts' => false])
            ->assertOk();

        $this->assertFalse($node->fresh()?->accepts_new_accounts);
    }

    #[Test]
    public function a_region_still_holding_an_estate_cannot_be_switched_off(): void
    {
        $region = Region::factory()->create();
        $datacenter = Datacenter::factory()->create(['region_id' => $region->id]);
        ComputeCluster::factory()->create(['datacenter_id' => $datacenter->id, 'status' => ClusterStatus::Active]);

        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/regions/'.$region->id, ['is_active' => false])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'infrastructure.still_in_use');

        $this->assertTrue($region->fresh()?->is_active);

        // And the thing an operator actually wants — stop selling here — is
        // never refused, because nothing running depends on it.
        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/regions/'.$region->id, ['accepts_new_services' => false])
            ->assertOk();
    }

    #[Test]
    public function a_pool_cannot_be_reclassified_into_customer_capacity(): void
    {
        /*
         * The scope decides whether the allocator may hand an address to a
         * customer workload. A management pool that could be edited into a
         * public one would move the control plane into the customer estate
         * with a dropdown, and every address already allocated from it would
         * change meaning retrospectively.
         */
        $pool = IpPool::factory()->management()->create();

        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/ip-pools/'.$pool->id, ['scope' => 'public'])
            ->assertStatus(422);

        $this->assertFalse($pool->fresh()?->scope->isCustomerAllocatable());
    }

    #[Test]
    public function a_stale_edit_does_not_overwrite_a_newer_one(): void
    {
        /*
         * Two operators on the same cluster. The second one opened the form
         * before the first saved, and without a version the later write wins
         * silently — which on this row means an endpoint or a TLS setting
         * quietly reverting.
         */
        $cluster = ComputeCluster::factory()->create(['name' => 'Original']);

        $first = $this->actingAs($this->operator)
            ->getJson('/api/admin/infrastructure/clusters')
            ->assertOk();

        $version = collect((array) $first->json('data'))->firstWhere('id', $cluster->id)['version'] ?? null;

        $this->assertNotNull($version, 'The list does not publish a version, so a client cannot send one.');

        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/clusters/'.$cluster->id, [
                'name' => 'Renamed by the first operator',
                'version' => $version,
            ])
            ->assertOk();

        // The second operator, still holding the version they loaded.
        $this->actingAs($this->operator)
            ->putJson('/api/admin/infrastructure/clusters/'.$cluster->id, [
                'name' => 'Renamed by the second operator',
                'version' => $version,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'infrastructure.stale_write');

        $this->assertSame('Renamed by the first operator', $cluster->fresh()?->name);
    }

    #[Test]
    public function staff_without_the_permission_cannot_correct_the_estate(): void
    {
        $region = Region::factory()->create();

        $support = User::factory()->create();
        $support->syncRoles([Role::Support->value]);

        $this->actingAs($support)
            ->putJson('/api/admin/infrastructure/regions/'.$region->id, ['city' => 'Nowhere'])
            ->assertForbidden();

        $this->assertNotSame('Nowhere', $region->fresh()?->city);
    }
}
