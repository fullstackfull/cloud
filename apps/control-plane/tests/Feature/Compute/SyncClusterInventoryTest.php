<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Lynomia\Modules\Compute\Application\Actions\SyncClusterInventory;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Discovery, and the things it must not do.
 *
 * This action runs on a schedule against every cluster the platform owns, so
 * anything it does, it does to the whole fleet. Two properties therefore
 * matter more than the data it collects:
 *
 *  - it never mutates anything at the hypervisor. Asserted here against the
 *    recorded requests, not against the code;
 *  - it never writes the platform's own commitments. The hypervisor does not
 *    know about a machine that is still being built, and a sync that
 *    recomputed allocated_memory_mib from what it could see would free
 *    capacity that is genuinely spoken for — and the scheduler would place a
 *    second machine on it within the minute.
 */
final class SyncClusterInventoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reading_a_clusters_inventory_issues_only_get_requests(): void
    {
        $this->fakeProxmox();

        app(SyncClusterInventory::class)->execute($this->proxmoxCluster());

        $this->assertNotEmpty(Http::recorded());

        foreach (Http::recorded() as [$request]) {
            $this->assertSame(
                'GET',
                $request->method(),
                'Inventory discovery must never create, modify or destroy anything at the provider.',
            );
        }

        // Belt and braces: not one of the endpoints that changes something was
        // touched, whatever the method.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/status/start')
            || str_contains($request->url(), '/status/stop')
            || str_ends_with($request->url(), '/config'));
    }

    #[Test]
    public function a_newly_discovered_node_is_recorded_but_not_put_into_service(): void
    {
        $cluster = $this->fakeCluster();

        $result = app(SyncClusterInventory::class)->execute($cluster);

        $this->assertSame(2, $result->nodesReported);
        $this->assertSame(2, $result->nodesCreated);
        $this->assertSame(0, $result->nodesUpdated);

        $node = ComputeNode::query()->where('provider_name', 'pve-01')->firstOrFail();

        /*
         * Maintenance, not active. Discovery is not authorisation: a node that
         * answers a GET has not necessarily been cabled, patched or added to
         * monitoring, and placing a customer on it the moment it appears is
         * how a machine ends up on a box somebody is still building.
         */
        $this->assertSame(NodeStatus::Maintenance, $node->status);
        $this->assertSame(32, $node->cpu_cores);
        $this->assertSame(65536, $node->memory_mib);
        $this->assertTrue($node->is_healthy);
        $this->assertNotNull($node->last_seen_at);
    }

    #[Test]
    public function the_platforms_own_commitments_are_never_written_by_a_sync(): void
    {
        $cluster = $this->fakeCluster();

        $node = ComputeNode::factory()->for($cluster, 'cluster')->named('pve-01')->create([
            'status' => NodeStatus::Active,
            'cpu_cores' => 8,
            'memory_mib' => 16384,
            'allocated_cpu_cores' => 6,
            'allocated_memory_mib' => 12288,
            'allocated_storage_gib' => 400,
            'vm_count' => 3,
        ]);

        app(SyncClusterInventory::class)->execute($cluster);

        $node->refresh();

        // Physical fact was refreshed...
        $this->assertSame(32, $node->cpu_cores);
        $this->assertSame(65536, $node->memory_mib);

        // ...and the commitments were not touched. A machine mid-build is
        // invisible to the hypervisor and would otherwise vanish from the
        // books.
        $this->assertSame(6, $node->allocated_cpu_cores);
        $this->assertSame(12288, $node->allocated_memory_mib);
        $this->assertSame(400, $node->allocated_storage_gib);
        $this->assertSame(3, $node->vm_count);
    }

    #[Test]
    public function a_status_an_operator_set_survives_the_sync(): void
    {
        $cluster = $this->fakeCluster();

        $draining = ComputeNode::factory()->for($cluster, 'cluster')->named('pve-01')
            ->status(NodeStatus::Draining)->create();

        app(SyncClusterInventory::class)->execute($cluster);

        // The node is perfectly reachable, which is exactly why this matters:
        // a background job that noticed it answers must not undo an operator's
        // decision to empty it.
        $this->assertSame(NodeStatus::Draining, $draining->refresh()->status);
    }

    #[Test]
    public function a_node_reported_offline_is_marked_unhealthy_rather_than_removed(): void
    {
        $cluster = $this->fakeCluster();

        app(SyncClusterInventory::class)->execute($cluster);

        $offline = ComputeNode::query()->where('provider_name', 'pve-02')->firstOrFail();

        $this->assertFalse($offline->is_healthy);
        // Still there: the row is the only record of where a customer's
        // machines are, and a node dropping off the API is the moment that
        // record matters most.
        $this->assertDatabaseHas('compute_nodes', ['provider_name' => 'pve-02']);
    }

    #[Test]
    public function a_node_reported_offline_keeps_the_hardware_the_platform_recorded(): void
    {
        /*
         * The regression this exists for. Proxmox lists a node that has
         * dropped out of the cluster with maxcpu and maxmem at zero, and the
         * sync wrote them straight through — erasing the recorded size of a
         * box that is still holding customers' machines, on one bad pass, for
         * a node the module elsewhere refuses even to delete.
         *
         * The previous fixture hid it by giving the offline node full
         * capacity, which no real cluster does.
         */
        config()->set('compute.fake.nodes', [
            ['name' => 'pve-01', 'online' => false, 'cpu_cores' => 0, 'memory_total_mib' => 0],
        ]);

        $cluster = ComputeCluster::factory()->create();

        $node = ComputeNode::factory()->for($cluster, 'cluster')->named('pve-01')->create([
            'cpu_cores' => 64,
            'memory_mib' => 262144,
            'allocated_memory_mib' => 131072,
            'vm_count' => 9,
        ]);

        app(SyncClusterInventory::class)->execute($cluster);

        $node->refresh();

        $this->assertSame(64, $node->cpu_cores, 'An offline node had its recorded CPU capacity erased.');
        $this->assertSame(262144, $node->memory_mib, 'An offline node had its recorded memory erased.');

        // What the sync IS allowed to conclude from an offline node.
        $this->assertFalse($node->is_healthy);
        $this->assertSame(9, $node->vm_count);
    }

    #[Test]
    public function a_node_the_cluster_stops_reporting_is_flagged_and_kept(): void
    {
        $cluster = $this->fakeCluster();

        $vanished = ComputeNode::factory()->for($cluster, 'cluster')->named('pve-99')->create([
            'is_healthy' => true,
            'allocated_memory_mib' => 8192,
            'vm_count' => 2,
        ]);

        $result = app(SyncClusterInventory::class)->execute($cluster);

        $this->assertSame(['pve-99'], $result->missingNodes);
        $this->assertTrue($result->hasMissingNodes());

        $vanished->refresh();
        $this->assertFalse($vanished->is_healthy);
        // Deleting it would cascade its machines' node_id to null and lose the
        // only record of where they were.
        $this->assertSame(2, $vanished->vm_count);
        $this->assertSame(8192, $vanished->allocated_memory_mib);
    }

    #[Test]
    public function storage_pools_are_recorded_once_and_not_duplicated_by_a_second_pass(): void
    {
        $cluster = $this->fakeCluster();

        $first = app(SyncClusterInventory::class)->execute($cluster);
        $this->assertSame(2, $first->storagesCreated);
        $this->assertSame(0, $first->storagesUpdated);

        $second = app(SyncClusterInventory::class)->execute($cluster);

        // A shared pool is one row for the cluster, not one per node: counting
        // a Ceph pool once per node would let the scheduler commit the same
        // space several times over.
        $this->assertSame(0, $second->storagesCreated);
        $this->assertSame(2, $second->storagesUpdated);
        $this->assertSame(2, ComputeStorage::query()->count());

        $shared = ComputeStorage::query()->where('provider_name', 'ceph-pool')->firstOrFail();
        $this->assertNull($shared->node_id);
        $this->assertTrue($shared->shared);
    }

    #[Test]
    public function the_class_an_operator_gave_a_pool_is_never_overwritten_by_a_guess(): void
    {
        $cluster = $this->fakeCluster();

        app(SyncClusterInventory::class)->execute($cluster);

        $pool = ComputeStorage::query()->where('provider_name', 'local-nvme')->firstOrFail();
        $pool->fill(['storage_class' => StorageClass::Ssd])->save();

        app(SyncClusterInventory::class)->execute($cluster);

        // What a pool is sold as is a commercial decision, and a sync that
        // re-guessed it from the pool's name would move machines onto the
        // wrong tier the first time somebody renamed a volume group.
        $this->assertSame(StorageClass::Ssd, $pool->refresh()->storage_class);
        // Capacity figures, which are facts, were refreshed.
        $this->assertSame(3584, $pool->available_gib);
    }

    #[Test]
    public function a_successful_sync_stamps_the_cluster_and_clears_the_last_error(): void
    {
        $cluster = $this->fakeCluster();
        $cluster->fill(['last_sync_error' => 'something went wrong last time'])->save();

        app(SyncClusterInventory::class)->execute($cluster);

        $cluster->refresh();

        $this->assertNotNull($cluster->last_synced_at);
        $this->assertNull($cluster->last_sync_error);
    }

    #[Test]
    public function a_failing_cluster_records_why_and_the_failure_still_propagates(): void
    {
        Http::fake(['*' => Http::response(['data' => null, 'message' => 'permission denied'], 403)]);

        $cluster = $this->proxmoxCluster();

        try {
            app(SyncClusterInventory::class)->execute($cluster);

            $this->fail('A cluster that refused the request reported a successful sync.');
        } catch (ComputeProviderException $e) {
            $this->assertSame('compute.provider_request_failed', $e->errorCode());
        }

        $cluster->refresh();

        // Recorded where an operator will see it without reading the logs, and
        // re-thrown so the scheduler that called it does not believe the fleet
        // is up to date.
        $this->assertNotNull($cluster->last_sync_error);
        $this->assertNull($cluster->last_synced_at);
    }

    /**
     * A two-node cluster driven by the fake, configured rather than stubbed.
     */
    private function fakeCluster(): ComputeCluster
    {
        config()->set('compute.fake.nodes', [
            [
                'name' => 'pve-01',
                'online' => true,
                'cpu_cores' => 32,
                'memory_total_mib' => 65536,
                'memory_used_mib' => 16384,
                'cpu_usage' => 0.2,
                'storages' => [
                    ['name' => 'local-nvme', 'class' => StorageClass::Nvme->value, 'total_gib' => 4096, 'available_gib' => 3584],
                    ['name' => 'ceph-pool', 'class' => StorageClass::Ceph->value, 'shared' => true, 'total_gib' => 65536, 'available_gib' => 40960],
                ],
            ],
            // Zeroes, which is what a real cluster reports for a node that
            // has dropped out — not the full capacity the fixture used to
            // claim, which quietly made the offline path untested.
            ['name' => 'pve-02', 'online' => false, 'cpu_cores' => 0, 'memory_total_mib' => 0],
        ]);

        return ComputeCluster::factory()->create();
    }

    private function proxmoxCluster(): ComputeCluster
    {
        config()->set('compute.credentials.test-cluster', [
            'token_id' => 'lynomia@pve!control-plane',
            'token_secret' => 'b7f3c1de-4a2e-4f0c-9f77-0c1d2e3f4a5b',
        ]);

        return ComputeCluster::factory()->proxmox()->create();
    }

    private function fakeProxmox(): void
    {
        Http::fake(function (Request $request) {
            return match (true) {
                str_ends_with($request->url(), '/api2/json/nodes') => Http::response(['data' => [
                    ['node' => 'pve-01', 'status' => 'online', 'maxcpu' => 32, 'maxmem' => 68719476736, 'mem' => 17179869184],
                ]]),
                str_contains($request->url(), '/storage') => Http::response(['data' => [
                    ['storage' => 'local-nvme', 'type' => 'lvmthin', 'shared' => 0, 'total' => 4398046511104, 'avail' => 3848290697216, 'active' => 1],
                ]]),
                default => Http::response(['data' => []]),
            };
        });
    }
}
