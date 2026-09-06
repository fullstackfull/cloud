<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Application\Actions\ReleaseNodeCapacity;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shared storage is the one capacity dimension that is not per node.
 *
 * A Ceph pool or an NFS export is visible from every node in the cluster.
 * Counting its capacity into each node's own figure makes the scheduler believe
 * in as many copies of the pool as there are nodes able to reach it, and the
 * fleet oversells it by exactly that factor. The symptom reaches an operator as
 * customer machines failing to start on a full datastore — which reads as a
 * storage fault rather than as a control-plane one, and so gets investigated in
 * the wrong place.
 */
final class SharedStorageCommitmentTest extends TestCase
{
    use RefreshDatabase;

    private ComputeCluster $cluster;

    private ComputeStorage $pool;

    /** @var list<ComputeNode> */
    private array $nodes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cluster = ComputeCluster::factory()->create();

        // Three nodes, all able to see one 300 GiB shared pool.
        $this->nodes = [];
        for ($i = 1; $i <= 3; $i++) {
            $this->nodes[] = ComputeNode::factory()->create([
                'cluster_id' => $this->cluster->id,
                'provider_name' => "pve-0{$i}",
                'cpu_cores' => 64,
                'memory_mib' => 262144,
                'storage_gib' => 300,
            ]);
        }

        $this->pool = ComputeStorage::factory()->shared()->create([
            'cluster_id' => $this->cluster->id,
            'total_gib' => 300,
            'available_gib' => 300,
        ]);
    }

    private function resources(int $diskGib): VmResources
    {
        return new VmResources(vcpu: 2, memoryMib: 4096, diskGib: $diskGib);
    }

    #[Test]
    public function a_shared_pool_is_committed_once_not_once_per_node(): void
    {
        $reserve = app(ReserveNodeCapacity::class);

        // 100 GiB on each of three different nodes fills the 300 GiB pool
        // exactly. Per-node accounting would see 300 GiB free on every node and
        // happily accept a fourth.
        foreach ($this->nodes as $node) {
            $reserve->execute($node, $this->resources(100), storageId: $this->pool->id);
        }

        $this->assertSame(300, (int) $this->pool->fresh()->committed_gib);
        $this->assertSame(0, $this->pool->fresh()->freeGib());

        $this->expectException(NodeCapacityExceededException::class);

        $reserve->execute($this->nodes[0], $this->resources(1), storageId: $this->pool->id);
    }

    #[Test]
    public function releasing_returns_the_space_to_the_pool(): void
    {
        $reserve = app(ReserveNodeCapacity::class);
        $release = app(ReleaseNodeCapacity::class);

        $reserve->execute($this->nodes[0], $this->resources(200), storageId: $this->pool->id);
        $this->assertSame(100, $this->pool->fresh()->freeGib());

        $release->execute($this->nodes[0], $this->resources(200), storageId: $this->pool->id);
        $this->assertSame(300, $this->pool->fresh()->freeGib());
    }

    #[Test]
    public function a_double_release_cannot_drive_the_pool_negative(): void
    {
        $reserve = app(ReserveNodeCapacity::class);
        $release = app(ReleaseNodeCapacity::class);

        $reserve->execute($this->nodes[0], $this->resources(100), storageId: $this->pool->id);

        // A destroy retried after a timeout, and a reconciliation removing the
        // same machine, both release. A pool advertising more space than it has
        // would be believed by the scheduler.
        $release->execute($this->nodes[0], $this->resources(100), storageId: $this->pool->id);
        $release->execute($this->nodes[0], $this->resources(100), storageId: $this->pool->id);

        $this->assertSame(0, (int) $this->pool->fresh()->committed_gib);
        $this->assertSame(300, $this->pool->fresh()->freeGib());
    }

    #[Test]
    public function two_placements_on_different_nodes_contend_on_the_shared_pool(): void
    {
        /*
         * The node rows are not contended at all here — the two placements are
         * on different machines. Only the pool row is, which is exactly why the
         * commitment has to be locked separately from the node.
         */
        config()->set('database.connections.worker_b', config('database.connections.pgsql'));

        $reserve = app(ReserveNodeCapacity::class);

        $reserve->execute($this->nodes[0], $this->resources(250), storageId: $this->pool->id);

        $this->expectException(NodeCapacityExceededException::class);

        $reserve->execute($this->nodes[1], $this->resources(250), storageId: $this->pool->id);
    }

    #[Test]
    public function a_pool_with_no_reported_figure_is_treated_as_usable(): void
    {
        // Refusing to place anything on a pool whose numbers have not arrived
        // would empty the fleet the first time an inventory sync failed.
        $unknown = ComputeStorage::factory()->shared()->create([
            'cluster_id' => $this->cluster->id,
            'provider_name' => 'ceph-unreported',
            'total_gib' => null,
            'available_gib' => null,
        ]);

        app(ReserveNodeCapacity::class)
            ->execute($this->nodes[0], $this->resources(100), storageId: $unknown->id);

        $this->assertNull($unknown->fresh()->freeGib());
        $this->assertSame(100, (int) $unknown->fresh()->committed_gib);
    }

    #[Test]
    public function local_storage_still_accounts_per_node(): void
    {
        // Local NVMe is genuinely per node; the fix must not change that.
        $localA = ComputeStorage::factory()->onNode($this->nodes[0])->create([
            'provider_name' => 'local-nvme',
            'total_gib' => 200,
            'available_gib' => 200,
        ]);
        $localB = ComputeStorage::factory()->onNode($this->nodes[1])->create([
            'provider_name' => 'local-nvme',
            'total_gib' => 200,
            'available_gib' => 200,
        ]);

        $reserve = app(ReserveNodeCapacity::class);
        $reserve->execute($this->nodes[0], $this->resources(150), storageId: $localA->id);
        $reserve->execute($this->nodes[1], $this->resources(150), storageId: $localB->id);

        $this->assertSame(50, $localA->fresh()->freeGib());
        $this->assertSame(50, $localB->fresh()->freeGib());
    }
}
