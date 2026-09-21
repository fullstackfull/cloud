<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An empty deployment, an operator, and nothing else.
 *
 * ---------------------------------------------------------------------------
 * The dead end
 * ---------------------------------------------------------------------------
 *
 * Three writers existed and each began by finding a parent that had no writer
 * of its own: `datacenters.store` found a Region, `racks.store` found a
 * Datacenter, `templates.store` found a ComputeCluster. Region and
 * ComputeCluster could not be created through any supported path, so two of
 * the three were unreachable on a fresh deployment and the third only through
 * the first. Network, Subnet, IpPool, HostingNode, DedicatedServer and
 * BmcEndpoint had no writer at all.
 *
 * What was left was a platform whose inventory could only be established with
 * a SQL client, an edited seeder, or the reference topology — which is a model
 * of an estate and explicitly not one.
 *
 * ---------------------------------------------------------------------------
 * What this file proves, and how
 * ---------------------------------------------------------------------------
 *
 * Every row here is created by an HTTP request an operator could make. No
 * factory builds anything under test, no seeder runs except the one a
 * production deployment runs (roles and permissions, which create no rows a
 * customer or an estate could use), and no query writes. The chain is built in
 * order, parent before child, because that is the claim: the order is
 * *operable*, not merely that the leaf endpoint exists.
 */
final class AnOperatorCanBuildTheEstateFromNothingTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        // What a production deployment runs: the permission catalogue and the
        // default roles. It creates no users, no catalogue and no estate.
        $this->seed(RolePermissionSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->syncRoles([Role::SuperAdmin->value]);
    }

    // ---- the chain, parent before child ------------------------------------

    #[Test]
    public function an_operator_creates_a_region(): void
    {
        $this->assertSame(0, Region::query()->count(), 'The estate is not empty; this proves nothing.');

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/regions', [
                'slug' => 'kw-central',
                'name' => ['en' => 'Kuwait Central', 'ar' => 'الكويت الوسطى'],
                'country' => 'KW',
                'city' => 'Kuwait City',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'kw-central');

        /** @var Region $region */
        $region = Region::query()->sole();

        $this->assertSame('KW', $region->country);
        $this->assertSame('Kuwait Central', $region->nameFor('en'));
        $this->assertTrue($region->is_active);

        $this->assertSame(
            1,
            AuditEntry::query()->where('action', AuditAction::RegionRegistered)->count(),
            'A region appeared with nothing saying who put it there.',
        );
    }

    #[Test]
    public function the_datacenter_writer_that_could_not_find_a_region_now_can(): void
    {
        $region = $this->createRegion();

        // The exact call that used to 404 on a fresh deployment, because
        // Region::findOrFail had nothing to find.
        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/datacenters', [
                'region_id' => $region,
                'slug' => 'kw-dc-1',
                'name' => 'Kuwait DC 1',
                'facility' => 'Zajil',
            ])
            ->assertCreated();

        $this->assertSame($region, Datacenter::query()->sole()->region_id);
    }

    #[Test]
    public function an_operator_creates_a_compute_cluster(): void
    {
        $datacenter = $this->createDatacenter();

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/clusters', [
                'datacenter_id' => $datacenter,
                'slug' => 'kw-pve-1',
                'name' => 'Kuwait Proxmox 1',
                'driver' => 'proxmox',
                'api_endpoint' => 'https://pve.internal.example:8006',
                'verify_tls' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'kw-pve-1');

        /** @var ComputeCluster $cluster */
        $cluster = ComputeCluster::query()->sole();

        $this->assertSame($datacenter, $cluster->datacenter_id);
        $this->assertTrue($cluster->verify_tls);

        // Configured, not verified: nothing was contacted and the row says so.
        $this->assertNull($cluster->last_synced_at);
    }

    #[Test]
    public function the_template_writer_that_could_not_find_a_cluster_now_can(): void
    {
        $cluster = $this->createCluster();

        // The third blocked findOrFail. VmTemplateController was written,
        // routed and tested, and was unreachable on a fresh deployment.
        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/templates', [
                'cluster_id' => $cluster,
                'slug' => 'ubuntu-lts',
                'name' => ['en' => 'Ubuntu LTS', 'ar' => 'أوبنتو إل تي إس'],
                'os_family' => 'ubuntu',
                'os_version' => '24.04',
                'architecture' => 'x86_64',
                'provider_reference' => 'local:vztmpl/ubuntu-24.04',
                'cloud_init' => true,
                'guest_agent' => true,
                'requires_licence' => false,
            ])
            ->assertCreated();

        $this->assertSame($cluster, VmTemplate::query()->sole()->cluster_id);
    }

    #[Test]
    public function an_operator_creates_a_network(): void
    {
        $datacenter = $this->createDatacenter();

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/networks', [
                'datacenter_id' => $datacenter,
                'slug' => 'kw-public-1',
                'name' => 'Kuwait Public 1',
                'purpose' => 'public',
                'vlan_id' => 100,
                'bridge' => 'vmbr0',
                'is_customer_facing' => true,
            ])
            ->assertCreated();

        /** @var Network $network */
        $network = Network::query()->sole();

        $this->assertSame($datacenter, $network->datacenter_id);
        $this->assertTrue($network->is_customer_facing);
    }

    #[Test]
    public function an_operator_creates_a_customer_allocatable_pool_and_its_subnet(): void
    {
        $datacenter = $this->createDatacenter();

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/ip-pools', [
                'datacenter_id' => $datacenter,
                'slug' => 'kw-public-v4',
                'name' => 'Kuwait Public IPv4',
                'ip_version' => 4,
                'scope' => 'public',
                'quarantine_days' => 7,
            ])
            ->assertCreated();

        /** @var IpPool $pool */
        $pool = IpPool::query()->sole();

        $this->assertSame(IpPoolScope::Public, $pool->scope);
        $this->assertTrue($pool->scope->isCustomerAllocatable());

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/ip-pools/'.$pool->id.'/subnets', [
                'cidr' => '203.0.113.0/24',
                'gateway' => '203.0.113.1',
            ])
            ->assertCreated();

        /** @var Subnet $subnet */
        $subnet = Subnet::query()->sole();

        $this->assertSame($pool->id, $subnet->ip_pool_id);
        $this->assertSame(4, $subnet->ip_version->value);
        $this->assertSame(24, $subnet->prefix_length);
    }

    #[Test]
    public function an_operator_creates_a_hosting_node(): void
    {
        $datacenter = $this->createDatacenter();

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/hosting-nodes', [
                'datacenter_id' => $datacenter,
                'slug' => 'kw-web-1',
                'hostname' => 'web1.internal.example',
                'panel' => 'cpanel',
                'api_endpoint' => 'https://web1.internal.example:2087',
                'max_accounts' => 400,
            ])
            ->assertCreated();

        /** @var HostingNode $node */
        $node = HostingNode::query()->sole();

        $this->assertSame($datacenter, $node->datacenter_id);
        $this->assertSame(0, $node->account_count);
        // Configured, not verified: the panel has not been asked anything.
        $this->assertFalse($node->panel_licensed);
    }

    #[Test]
    public function an_operator_creates_dedicated_stock_and_its_bmc(): void
    {
        $datacenter = $this->createDatacenter();

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/dedicated', [
                'datacenter_id' => $datacenter,
                'manufacturer' => 'Supermicro',
                'model' => 'SYS-1029P',
                'serial' => 'SM-KW-0001',
                'hardware_profile' => 'ded-standard-1',
                'height_units' => 1,
            ])
            ->assertCreated();

        /** @var DedicatedServer $server */
        $server = DedicatedServer::query()->sole();

        $this->assertSame($datacenter, $server->datacenter_id);
        $this->assertNull($server->customer_id, 'New stock belongs to nobody.');

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/dedicated/'.$server->id.'/bmc', [
                'protocol' => 'redfish',
                'address' => '10.20.0.7',
                'port' => 443,
                'username' => 'lynomia-ro',
                'verify_tls' => true,
            ])
            ->assertCreated();

        /** @var BmcEndpoint $bmc */
        $bmc = BmcEndpoint::query()->sole();

        $this->assertSame($server->id, $bmc->dedicated_server_id);
        $this->assertNull($bmc->last_contacted_at, 'Writing an endpoint down is not reaching it.');
    }

    // ---- what the estate must not become -----------------------------------

    #[Test]
    public function a_management_pool_is_creatable_and_still_is_not_customer_capacity(): void
    {
        $datacenter = $this->createDatacenter();

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/ip-pools', [
                'datacenter_id' => $datacenter,
                'slug' => 'kw-mgmt-v4',
                'name' => 'Kuwait Management',
                'ip_version' => 4,
                'scope' => 'management',
            ])
            ->assertCreated();

        /** @var IpPool $pool */
        $pool = IpPool::query()->sole();

        /*
         * The whole point of an operator form is that it cannot create a
         * configuration the runtime would reject. A management pool reaches
         * the hypervisor and BMC control planes; the allocator refuses to hand
         * one to a customer service, and the placement rule refuses to count
         * one as capacity. A form that could turn one into customer capacity
         * would be a lateral-movement path with a Create button.
         */
        $this->assertFalse($pool->scope->isCustomerAllocatable());
    }

    #[Test]
    public function a_child_cannot_be_hung_off_a_parent_in_another_datacenter(): void
    {
        $here = $this->createDatacenter('kw-dc-1');
        $elsewhere = $this->createDatacenter('kw-dc-2', reuseRegion: true);

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/ip-pools', [
                'datacenter_id' => $here,
                'slug' => 'kw-public-v4',
                'name' => 'Pool',
                'ip_version' => 4,
                'scope' => 'public',
            ])
            ->assertCreated();

        $pool = IpPool::query()->sole();

        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/networks', [
                'datacenter_id' => $elsewhere,
                'slug' => 'other-net',
                'name' => 'Other',
                'purpose' => 'public',
            ])
            ->assertCreated();

        $network = Network::query()->where('slug', 'other-net')->sole();

        // A subnet joins a pool to a network; they have to be in the same
        // building, or the address plan describes somewhere that isn't real.
        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/ip-pools/'.$pool->id.'/subnets', [
                'cidr' => '203.0.113.0/24',
                'network_id' => $network->id,
            ])
            ->assertStatus(422);

        $this->assertSame(0, Subnet::query()->count());
    }

    #[Test]
    public function an_unsafe_endpoint_is_refused_by_the_policy_that_already_exists(): void
    {
        $datacenter = $this->createDatacenter();

        /*
         * Loopback, the cloud metadata service and a credential in the URL
         * are what the outbound policy exists to stop, and a Create button is
         * not a reason to weaken it. 409 rather than 422 because that is what
         * EndpointRefused already answers everywhere else it is raised; this
         * path reuses the policy rather than restating it.
         */
        $unsafe = [
            'http://127.0.0.1:8006',
            'https://169.254.169.254/',
            'https://root:hunter2@pve.example:8006',
            'http://pve.example:8006',
        ];

        foreach ($unsafe as $endpoint) {
            $this->actingAs($this->operator)
                ->postJson('/api/admin/infrastructure/clusters', [
                    'datacenter_id' => $datacenter,
                    'slug' => 'bad-'.substr(md5($endpoint), 0, 8),
                    'name' => 'Bad',
                    'driver' => 'proxmox',
                    'api_endpoint' => $endpoint,
                ])
                ->assertStatus(409);
        }

        $this->assertSame(0, ComputeCluster::query()->count());

        // The positive control: the same form accepts a cluster on the
        // management network, so the refusals above are the policy and not a
        // form that refuses everything.
        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/clusters', [
                'datacenter_id' => $datacenter,
                'slug' => 'good',
                'name' => 'Good',
                'driver' => 'proxmox',
                'api_endpoint' => 'https://pve.mgmt.example:8006',
            ])
            ->assertCreated();

        $this->assertSame(1, ComputeCluster::query()->count());
    }

    // ---- who may do any of this --------------------------------------------

    #[Test]
    public function a_customer_cannot_touch_the_estate(): void
    {
        $datacenter = $this->createDatacenter();

        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->members()->create(['user_id' => $user->id, 'role' => 'owner', 'accepted_at' => now()]);
        $user->syncRoles([Role::Customer->value]);

        $writes = [
            ['post', '/api/admin/infrastructure/regions', ['slug' => 'x', 'name' => ['en' => 'X'], 'country' => 'KW']],
            ['post', '/api/admin/infrastructure/clusters', ['datacenter_id' => $datacenter, 'slug' => 'x', 'name' => 'X', 'driver' => 'proxmox']],
            ['post', '/api/admin/infrastructure/networks', ['datacenter_id' => $datacenter, 'slug' => 'x', 'name' => 'X', 'purpose' => 'public']],
            ['post', '/api/admin/infrastructure/ip-pools', ['datacenter_id' => $datacenter, 'slug' => 'x', 'name' => 'X', 'ip_version' => 4, 'scope' => 'public']],
            ['post', '/api/admin/infrastructure/hosting-nodes', ['datacenter_id' => $datacenter, 'slug' => 'x', 'hostname' => 'h.example', 'panel' => 'cpanel']],
            ['post', '/api/admin/infrastructure/dedicated', ['datacenter_id' => $datacenter, 'manufacturer' => 'M', 'model' => 'M', 'serial' => 'S']],
        ];

        foreach ($writes as [$method, $uri, $payload]) {
            $this->actingAs($user)->json($method, $uri, $payload)->assertForbidden();
        }

        // The positive control: the routes exist and the operator reaches
        // them, so the refusals above are authorisation and not absence.
        $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/regions', [
                'slug' => 'proof', 'name' => ['en' => 'Proof'], 'country' => 'KW',
            ])
            ->assertCreated();
    }

    #[Test]
    public function staff_without_the_permission_cannot_write_the_estate(): void
    {
        $datacenter = $this->createDatacenter();

        // Support answers customers. It holds none of the infrastructure
        // permissions, and a Create button is not a grant.
        $support = User::factory()->create();
        $support->syncRoles([Role::Support->value]);

        $this->actingAs($support)
            ->postJson('/api/admin/infrastructure/clusters', [
                'datacenter_id' => $datacenter, 'slug' => 'x', 'name' => 'X', 'driver' => 'proxmox',
            ])
            ->assertForbidden();

        $this->actingAs($support)
            ->postJson('/api/admin/infrastructure/ip-pools', [
                'datacenter_id' => $datacenter, 'slug' => 'x', 'name' => 'X', 'ip_version' => 4, 'scope' => 'public',
            ])
            ->assertForbidden();

        // And the role that is meant to do this can.
        $engineer = User::factory()->create();
        $engineer->syncRoles([Role::NetworkEngineer->value]);

        $this->actingAs($engineer)
            ->postJson('/api/admin/infrastructure/ip-pools', [
                'datacenter_id' => $datacenter, 'slug' => 'ok', 'name' => 'OK', 'ip_version' => 4, 'scope' => 'public',
            ])
            ->assertCreated();
    }

    // ---- fixtures, all of them through the operator API --------------------

    private function createRegion(string $slug = 'kw-central'): string
    {
        $response = $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/regions', [
                'slug' => $slug,
                'name' => ['en' => 'Kuwait', 'ar' => 'الكويت'],
                'country' => 'KW',
            ])
            ->assertCreated();

        return (string) $response->json('data.id');
    }

    private function createDatacenter(string $slug = 'kw-dc-1', bool $reuseRegion = false): string
    {
        $region = $reuseRegion && Region::query()->exists()
            ? (string) Region::query()->first()?->getKey()
            : $this->createRegion('region-'.$slug);

        $response = $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/datacenters', [
                'region_id' => $region,
                'slug' => $slug,
                'name' => 'Datacenter '.$slug,
            ])
            ->assertCreated();

        return (string) $response->json('data.id');
    }

    private function createCluster(): string
    {
        $response = $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/clusters', [
                'datacenter_id' => $this->createDatacenter(),
                'slug' => 'kw-pve-1',
                'name' => 'Proxmox 1',
                'driver' => 'proxmox',
            ])
            ->assertCreated();

        return (string) $response->json('data.id');
    }
}
