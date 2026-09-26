<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Application\Actions\SyncClusterInventory;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Services\NodeCapacityPolicy;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A node read from the controlled hypervisor's default fleet has disk.
 *
 * Found by round three's estate work (F-02): the simulator's default nodes
 * listed their storage pools but reported no storage TOTAL, so
 * `SyncClusterInventory` recorded every node it discovered at 0 GiB and the
 * capacity policy refused every placement on it with InsufficientStorage.
 * The end-to-end estate test had to configure `compute.fake.nodes` by hand to
 * get past it — a gap in the simulator's default, not in the estate.
 *
 * The real adapter never reports a node's storage total as a figure of its
 * own: `ProxmoxComputeProvider::withStorageTotals()` sums the active pools the
 * node can see, shared pools counted once per node. The simulator now does
 * the same with the pools it lists, so the two arrive at a node's disk by the
 * same rule. Nothing about the pools themselves is invented here; they are
 * the ones the default fleet always listed.
 */
final class TheSimulatorsDefaultFleetCanHoldAMachineTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_default_node_reports_the_disk_its_active_pools_hold(): void
    {
        foreach ((new FakeComputeProvider)->listNodes() as $node) {
            $active = array_filter($node->storages, static fn (object $storage): bool => $storage->active);

            $this->assertNotSame([], $active, $node->name.' lists no active pool.');
            $this->assertSame(
                array_sum(array_map(static fn (object $storage): int => (int) $storage->totalGib, $active)),
                $node->storageTotalGib,
                $node->name.' reports a storage total that is not the sum of its active pools.',
            );
            $this->assertSame(
                array_sum(array_map(static fn (object $storage): int => (int) $storage->availableGib, $active)),
                $node->storageAvailableGib,
            );
        }
    }

    #[Test]
    public function a_node_synced_from_the_default_fleet_can_hold_a_machine(): void
    {
        $cluster = ComputeCluster::factory()->create();

        app(SyncClusterInventory::class)->execute($cluster);

        $node = ComputeNode::query()->where('cluster_id', $cluster->getKey())->where('provider_name', 'pve-01')->firstOrFail();

        $this->assertGreaterThan(0, $node->storage_gib, 'The node was recorded with no disk at all.');

        // Discovery leaves it in maintenance; an operator puts it into service.
        $node->forceFill(['status' => NodeStatus::Active])->save();

        $assessment = (new NodeCapacityPolicy)->assess($node->refresh(), new VmResources(vcpu: 2, memoryMib: 2048, diskGib: 40));

        $this->assertTrue($assessment->fits, 'A small machine does not fit a node read from the default fleet: '.$assessment->detail);
    }
}
