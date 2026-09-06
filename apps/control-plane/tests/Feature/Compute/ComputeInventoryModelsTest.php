<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Domain\Enums\ComputeDriver;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The inventory's shape, and the arithmetic the scheduler trusts it for.
 *
 * The capacity helpers are tested here rather than through the scheduler
 * because every placement decision is built on them: an off-by-one in
 * usableMemoryMib() is not a wrong number on a dashboard, it is a hypervisor
 * that swaps.
 */
final class ComputeInventoryModelsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_inventory_hangs_together_from_region_down_to_machine(): void
    {
        $node = ComputeNode::factory()->create();
        $cluster = $node->cluster()->firstOrFail();
        $datacenter = $cluster->datacenter()->firstOrFail();

        ComputeStorage::factory()->onNode($node)->create();
        $machine = VirtualMachine::factory()->onNode($node)->create();

        $region = Region::query()->with('datacenters.clusters.nodes.storages')->findOrFail($datacenter->region_id);

        $this->assertCount(1, $region->datacenters);
        $this->assertCount(1, $region->datacenters[0]->clusters);
        $this->assertCount(1, $region->datacenters[0]->clusters[0]->nodes);
        $this->assertCount(1, $region->datacenters[0]->clusters[0]->nodes[0]->storages);

        $machine->load(['node', 'cluster', 'service']);
        $this->assertSame((string) $node->getKey(), (string) $machine->node?->getKey());
        $this->assertSame((string) $cluster->getKey(), (string) $machine->cluster?->getKey());
        $this->assertNotNull($machine->service);
    }

    #[Test]
    public function every_column_that_matters_is_cast_to_something_typed(): void
    {
        $node = ComputeNode::factory()->create();
        $cluster = ComputeCluster::factory()->create();
        $storage = ComputeStorage::factory()->create();
        $template = VmTemplate::factory()->create();
        $machine = VirtualMachine::factory()->create();

        $this->assertInstanceOf(NodeStatus::class, $node->fresh()?->status);
        $this->assertIsInt($node->fresh()?->memory_mib);
        $this->assertIsFloat($node->fresh()?->cpu_overcommit_ratio);
        $this->assertInstanceOf(ClusterStatus::class, $cluster->fresh()?->status);
        $this->assertInstanceOf(ComputeDriver::class, $cluster->fresh()?->driver);
        $this->assertInstanceOf(StorageClass::class, $storage->fresh()?->storage_class);
        $this->assertInstanceOf(CpuArchitecture::class, $template->fresh()?->architecture);
        $this->assertInstanceOf(PowerState::class, $machine->fresh()?->power_state);
    }

    #[Test]
    public function memory_arithmetic_keeps_the_hypervisors_own_share_out_of_the_pool(): void
    {
        $node = ComputeNode::factory()->create([
            'memory_mib' => 65536,
            'memory_headroom_percent' => 10,
            'allocated_memory_mib' => 16384,
        ]);

        $this->assertSame(58982, $node->usableMemoryMib());
        $this->assertSame(42598, $node->freeMemoryMib());
        $this->assertSame(27.8, round($node->memoryCommittedPercent(), 1));
    }

    #[Test]
    public function free_memory_never_goes_negative_however_oversold_a_node_is(): void
    {
        // Only reachable through a bug or a hand-edited row, but the
        // scheduler subtracts this number and a negative would read as
        // capacity rather than as an alarm.
        $node = ComputeNode::factory()->create([
            'memory_mib' => 8192,
            'memory_headroom_percent' => 10,
            'allocated_memory_mib' => 16384,
        ]);

        $this->assertSame(0, $node->freeMemoryMib());
    }

    #[Test]
    public function cpu_arithmetic_follows_the_overcommit_ratio(): void
    {
        $node = ComputeNode::factory()->create([
            'cpu_cores' => 24,
            'cpu_overcommit_ratio' => 3.5,
            'allocated_cpu_cores' => 20,
        ]);

        $this->assertSame(84, $node->usableCpuCores());
        $this->assertSame(64, $node->freeCpuCores());
    }

    #[Test]
    public function a_node_is_schedulable_only_when_it_is_both_active_and_healthy(): void
    {
        $this->assertTrue(ComputeNode::factory()->create()->isSchedulable());
        $this->assertFalse(ComputeNode::factory()->unhealthy()->create()->isSchedulable());
        $this->assertFalse(ComputeNode::factory()->status(NodeStatus::Draining)->create()->isSchedulable());

        $this->assertSame(1, ComputeNode::query()->schedulable()->count());
    }

    #[Test]
    public function a_node_that_never_reported_its_architecture_is_assumed_to_be_the_common_one(): void
    {
        $node = ComputeNode::factory()->create(['capabilities' => null]);
        $this->assertSame(CpuArchitecture::X86_64, $node->architecture());

        $arm = ComputeNode::factory()->create(['capabilities' => ['architecture' => 'aarch64']]);
        $this->assertSame(CpuArchitecture::Aarch64, $arm->architecture());
    }

    #[Test]
    public function local_storage_is_pinned_to_its_node_and_shared_storage_is_not(): void
    {
        $node = ComputeNode::factory()->create();
        $other = ComputeNode::factory()->create();

        $local = ComputeStorage::factory()->onNode($node)->available(500)->create();
        $shared = ComputeStorage::factory()->shared()->create(['available_gib' => 500]);

        $this->assertTrue($local->canHost((string) $node->getKey(), 400));
        $this->assertFalse($local->canHost((string) $other->getKey(), 400));
        $this->assertFalse($local->canHost((string) $node->getKey(), 600));

        $this->assertTrue($shared->canHost((string) $node->getKey(), 400));
        $this->assertTrue($shared->canHost((string) $other->getKey(), 400));

        // A pool the hypervisor has not reported on is usable: refusing to
        // place anything on it would empty the fleet the first time a sync
        // failed.
        $unreported = ComputeStorage::factory()->create(['available_gib' => null]);
        $this->assertTrue($unreported->canHost((string) $node->getKey(), 10_000));

        $this->assertFalse(ComputeStorage::factory()->inactive()->create()->canHost((string) $node->getKey(), 1));
    }

    #[Test]
    public function a_machine_reports_the_capacity_it_is_holding(): void
    {
        $machine = VirtualMachine::factory()->resources(8, 16384, 200)->create();

        $resources = $machine->resources();

        $this->assertSame(8, $resources->vcpu);
        $this->assertSame(16384, $resources->memoryMib);
        $this->assertSame(200, $resources->diskGib);

        // The row exists before the hypervisor confirms anything, which is
        // what makes a lost create response reconcilable.
        $this->assertFalse($machine->existsRemotely());
        $this->assertTrue(VirtualMachine::factory()->onNode(ComputeNode::factory()->create())->create()->existsRemotely());
    }

    #[Test]
    public function a_region_is_sellable_only_while_it_is_active_and_open(): void
    {
        Region::factory()->create();
        Region::factory()->closedToNewServices()->create();
        Region::factory()->inactive()->create();

        $this->assertSame(1, Region::query()->sellable()->count());
        $this->assertSame('Kuwait', Region::factory()->create()->nameFor('en'));
    }

    #[Test]
    public function a_template_is_installable_only_once_it_is_staged_on_a_cluster(): void
    {
        VmTemplate::factory()->create();
        VmTemplate::factory()->unstaged()->create();
        VmTemplate::factory()->inactive()->create();

        $this->assertSame(1, VmTemplate::query()->installable()->count());

        $windows = VmTemplate::factory()->windows()->create();
        $this->assertTrue($windows->os_family->isWindows());
        $this->assertSame('win11', $windows->os_family->proxmoxOsType());
        $this->assertTrue($windows->requires_licence);
        $this->assertTrue($windows->supportsUnattendedSetup());
    }

    #[Test]
    public function a_cluster_accepts_placement_only_while_it_is_active(): void
    {
        ComputeCluster::factory()->create();
        $degraded = ComputeCluster::factory()->status(ClusterStatus::Degraded)->create();
        $offline = ComputeCluster::factory()->status(ClusterStatus::Offline)->create();

        $this->assertSame(1, ComputeCluster::query()->schedulable()->count());
        $this->assertFalse($degraded->acceptsPlacement());
        // Degraded still answers the API — it has simply lost a node, and
        // everything already on it is still running.
        $this->assertTrue($degraded->status->isReachable());
        $this->assertFalse($offline->status->isReachable());
    }

    #[Test]
    public function a_datacenter_belongs_to_its_region(): void
    {
        $datacenter = Datacenter::factory()->create();

        $this->assertNotNull($datacenter->region()->first());
        $this->assertTrue($datacenter->is_active);
    }
}
