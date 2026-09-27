<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An address a customer machine is given is on a segment the machine can be
 * plugged into.
 *
 * CreateVpsHandler refuses, permanently, to build a machine whose address is
 * in a subnet naming no customer-attachable network with a bridge — there is
 * nowhere to plug it in, and a guess is what put customer machines on the
 * management VLAN. But the operator's subnet route took an allocatable block
 * in a customer pool with no network at all, and `mapping.network` counted
 * its addresses: "5 address(es) a customer machine can be given" on an estate
 * where every VPS build failed `vps.network_not_attachable`. And no route
 * attaches a network to a subnet afterwards, so the block could not be put
 * right.
 *
 * Both halves now: the route refuses such a block, and the preflight counts
 * only addresses on a segment a customer machine can be attached to.
 */
final class ACustomerBlockIsOnASegmentAMachineCanBeAttachedToTest extends TestCase
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
    public function a_block_a_customer_may_be_given_an_address_from_is_refused_without_a_network(): void
    {
        $this->register($this->pool(), ['cidr' => '203.0.113.0/29', 'gateway' => '203.0.113.1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'infrastructure.subnet_has_no_customer_network');

        $this->assertSame(0, Subnet::query()->count());
        $this->assertSame(0, IpAddress::query()->count());
    }

    #[Test]
    public function a_block_a_customer_may_be_given_an_address_from_is_refused_on_a_segment_no_customer_may_use(): void
    {
        $pool = $this->pool();

        foreach ([
            'management' => Network::factory()->management()->create(['datacenter_id' => $this->datacenter->getKey()]),
            'inactive' => Network::factory()->inactive()->create(['datacenter_id' => $this->datacenter->getKey()]),
            'not customer-facing' => Network::factory()->create(['datacenter_id' => $this->datacenter->getKey(), 'is_customer_facing' => false]),
        ] as $what => $network) {
            $this->register($pool, ['cidr' => '203.0.113.0/29', 'network_id' => $network->getKey()])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'infrastructure.subnet_has_no_customer_network');

            $this->assertSame(0, Subnet::query()->count(), 'a '.$what.' segment was accepted');
        }
    }

    #[Test]
    public function a_block_on_a_customer_segment_is_registered(): void
    {
        $network = Network::factory()->create(['datacenter_id' => $this->datacenter->getKey(), 'bridge' => 'vmbr1']);

        $this->register($this->pool(), ['cidr' => '203.0.113.0/29', 'gateway' => '203.0.113.1', 'network_id' => $network->getKey()])
            ->assertCreated()
            ->assertJsonPath('data.network_id', $network->getKey())
            ->assertJsonPath('data.allocatable_addresses', 5);
    }

    #[Test]
    public function held_space_and_management_blocks_need_no_customer_segment(): void
    {
        // Held space hands nothing out, and a management pool never serves a
        // customer: neither is an address a customer machine is plugged in at.
        $this->register($this->pool(), ['cidr' => '203.0.112.0/24', 'allocatable' => false])->assertCreated();
        $this->register($this->pool(IpPoolScope::Management), ['cidr' => '10.250.0.0/29'])->assertCreated();
    }

    #[Test]
    public function the_preflight_counts_only_addresses_on_a_segment_a_customer_machine_can_be_attached_to(): void
    {
        $this->aComputeEstate();
        $pool = $this->pool();

        // Written as the route used to allow, and as rows from before this
        // rule may be: a customer block with no network.
        $this->seeded(Subnet::factory()->forBlock('198.51.100.0/29')->create(['ip_pool_id' => $pool->getKey()]));

        $finding = $this->networkFinding();
        $this->assertSame('fail', $finding['status'], $finding['summary']);
        $this->assertStringContainsString('none of them is on a network a customer machine can be attached to', $finding['summary']);

        // A network, but with no bridge: still nowhere to plug a machine in.
        $bridgeless = Network::factory()->create(['datacenter_id' => $this->datacenter->getKey(), 'bridge' => null]);
        $this->seeded(Subnet::factory()->forBlock('198.51.100.8/29')->create(['ip_pool_id' => $pool->getKey(), 'network_id' => $bridgeless->getKey()]));
        $this->assertSame('fail', $this->networkFinding()['status']);

        // A management segment is never one.
        $management = Network::factory()->management()->create(['datacenter_id' => $this->datacenter->getKey()]);
        $this->seeded(Subnet::factory()->forBlock('198.51.100.16/29')->create(['ip_pool_id' => $pool->getKey(), 'network_id' => $management->getKey()]));
        $this->assertSame('fail', $this->networkFinding()['status']);

        // One block on a customer segment with a bridge: its five, and only
        // its five, are counted.
        $attachable = Network::factory()->create(['datacenter_id' => $this->datacenter->getKey(), 'bridge' => 'vmbr1']);
        $this->seeded(Subnet::factory()->forBlock('198.51.100.24/29')->create(['ip_pool_id' => $pool->getKey(), 'network_id' => $attachable->getKey()]));

        $finding = $this->networkFinding();
        $this->assertSame('pass', $finding['status'], $finding['summary']);
        $this->assertStringStartsWith('5 address(es) a customer machine can be given and attached', $finding['summary']);
    }

    // ---------------------------------------------------------------------

    private function pool(IpPoolScope $scope = IpPoolScope::Public): IpPool
    {
        return IpPool::factory()->create([
            'datacenter_id' => $this->datacenter->getKey(),
            'scope' => $scope,
        ]);
    }

    private function seeded(Subnet $subnet): void
    {
        app(SeedSubnetAddresses::class)->execute($subnet);
    }

    private function aComputeEstate(): void
    {
        $cluster = ComputeCluster::factory()->create(['status' => 'active']);

        ComputeNode::factory()->create([
            'cluster_id' => $cluster->getKey(),
            'status' => 'active',
            'is_healthy' => true,
            'last_seen_at' => now(),
        ]);
        ComputeStorage::factory()->create(['cluster_id' => $cluster->getKey(), 'is_active' => true]);
        VmTemplate::factory()->create(['cluster_id' => $cluster->getKey(), 'provider_reference' => 'local:vztmpl/debian-13']);
    }

    /**
     * @return array{status: string, summary: string}
     */
    private function networkFinding(): array
    {
        Artisan::call('infra:preflight', ['--mode' => 'simulation', '--product' => 'vps', '--json' => true]);

        /** @var array{checks: list<array<string, mixed>>} $report */
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);

        foreach ($report['checks'] as $check) {
            if ($check['id'] === 'mapping.network') {
                return ['status' => (string) $check['status'], 'summary' => (string) $check['summary']];
            }
        }

        $this->fail('mapping.network is not in the report.');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function register(IpPool $pool, array $body): TestResponse
    {
        return $this->actingAs($this->operator)
            ->postJson('/api/admin/infrastructure/ip-pools/'.$pool->getKey().'/subnets', $body);
    }
}
