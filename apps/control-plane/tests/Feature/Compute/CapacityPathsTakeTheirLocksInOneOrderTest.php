<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\ReleaseNodeCapacity;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Application\Actions\SyncClusterInventory;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every path that commits or gives back capacity locks its rows in one order:
 * the reservation row, then node rows ascending by id, then storage rows
 * ascending by id (ReserveNodeCapacity's docblock).
 *
 * A retry placed on another node moves its commitment (release on the old
 * node, reserve on the new one) in one transaction. Done as a release and then
 * a reserve it locked old node, old pool, new node, new pool — while a plain
 * reserve on the new node locks new node, then pool. A move A→B and an order
 * for B that share a pool then each hold what the other waits for, and
 * PostgreSQL kills one: an order, or a build. The same holds for two moves
 * between the same two nodes in opposite directions unless the nodes are
 * taken in one order.
 *
 * The inventory sync keeps the same order (D1, round six). It wrote node,
 * shared pool, node in the order the cluster reported them, in one
 * transaction - and an UPDATE takes the row's lock as surely as a
 * `for update` does. A sync holding the shared pool while it waited for node
 * B, and a reservation holding node B while it waited for the pool, killed
 * one of the two (40P01, 3/3 each way); the two-process race is
 * AnInventorySyncDoesNotDeadlockAReservationTest.
 *
 * Checked from the statements actually issued, in the order they ran - a
 * `for update` select and an UPDATE or DELETE of a row by id both count as
 * locking it - and from the FIRST lock on each row only: a row locked again
 * later in the same transaction is already held and waits for nothing. A
 * single process cannot make the deadlock happen, so this asserts the order
 * that prevents it (as MoneyPathsTakeTheirLocksInOneOrderTest does for the
 * money paths).
 */
final class CapacityPathsTakeTheirLocksInOneOrderTest extends TestCase
{
    use RefreshDatabase;

    private ComputeCluster $cluster;

    /** While true, statements run are another worker's, not the call's under test. */
    private bool $offTheRecord = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cluster = ComputeCluster::factory()->create();
    }

    #[Test]
    public function a_move_to_a_node_with_a_lower_id_takes_nodes_then_pools_in_order(): void
    {
        [$low, $high] = $this->twoNodesInIdOrder();
        $this->assertMoveIsOrdered(from: $high, to: $low);
    }

    #[Test]
    public function a_move_to_a_node_with_a_higher_id_takes_nodes_then_pools_in_order(): void
    {
        [$low, $high] = $this->twoNodesInIdOrder();
        $this->assertMoveIsOrdered(from: $low, to: $high);
    }

    #[Test]
    public function a_move_within_one_shared_pool_takes_both_nodes_before_the_pool(): void
    {
        [$low, $high] = $this->twoNodesInIdOrder();
        $shared = ComputeStorage::factory()->shared()->create([
            'cluster_id' => $this->cluster->id,
            'total_gib' => 1000,
            'available_gib' => 1000,
        ]);

        app(ReserveNodeCapacity::class)->execute($high, $this->resources(), storageId: $shared->id, reservationKey: 'job-1');

        $order = $this->firstLocks(fn () => app(ReserveNodeCapacity::class)
            ->execute($low, $this->resources(), storageId: $shared->id, reservationKey: 'job-1'));

        $this->assertOrdered($order);
        $this->assertSame([(string) $low->id, (string) $high->id], $this->idsOf($order, 'compute_nodes'));
        $this->assertSame((string) $low->id, NodeCapacityReservation::query()->whereNull('released_at')->sole()->node_id);
    }

    #[Test]
    public function a_plain_reservation_and_a_release_keep_the_same_order(): void
    {
        [$node] = $this->twoNodesInIdOrder();
        $pool = $this->poolOn($node);

        $reserve = $this->firstLocks(fn () => app(ReserveNodeCapacity::class)
            ->execute($node, $this->resources(), storageId: $pool->id, reservationKey: 'job-1'));
        $this->assertOrdered($reserve);

        $release = $this->firstLocks(fn () => app(ReleaseNodeCapacity::class)
            ->execute($node, $this->resources(), reservationKey: 'job-1'));
        $this->assertOrdered($release);
        $this->assertSame('node_capacity_reservations', $release[0][0]);
    }

    #[Test]
    public function a_sync_takes_every_node_before_any_pool_whatever_order_the_cluster_reports(): void
    {
        foreach ([false, true] as $pass => $reversed) {
            $this->reportTheFleet($reversed, $pass * 2);
            app(SyncClusterInventory::class)->execute($this->cluster->refresh());

            // Figures that moved since, so every row is written again.
            $this->reportTheFleet($reversed, $pass * 2 + 1);
            $order = $this->firstLocks(fn () => app(SyncClusterInventory::class)->execute($this->cluster->refresh()));

            $this->assertOrdered($order);
            $this->assertCount(2, $this->idsOf($order, 'compute_nodes'), 'The sync did not write both nodes: '.json_encode($order));
            $this->assertCount(3, $this->idsOf($order, 'compute_storages'), 'The sync did not write the shared pool and both local pools: '.json_encode($order));
        }
    }

    #[Test]
    public function a_move_takes_its_locks_from_the_reservation_as_locked_not_as_first_read(): void
    {
        /*
         * D4. The move chose which node and pool to lock from the row it read
         * without a lock, and threw away the locked re-read. A reservation
         * moved by another worker between the two reads (a retry of the same
         * build, placed elsewhere) left the move locking a node and pool it
         * no longer gives anything back to, and the release then locked the
         * node the row really names after the pools: out of order. The
         * other worker's move is modelled here as the row changing right
         * after any read of it that takes no lock.
         */
        $nodes = [];

        foreach (['pve-01', 'pve-02', 'pve-03'] as $name) {
            $nodes[] = ComputeNode::factory()->create([
                'cluster_id' => $this->cluster->id,
                'provider_name' => $name,
                'cpu_cores' => 64,
                'memory_mib' => 262144,
                'storage_gib' => 4096,
            ]);
        }

        usort($nodes, static fn (ComputeNode $a, ComputeNode $b): int => strcmp((string) $a->id, (string) $b->id));
        [$low, $middle, $high] = $nodes;
        $pools = [];

        foreach ($nodes as $node) {
            $pools[(string) $node->id] = $this->poolOn($node);
        }

        // Held on the middle node; the other worker's move takes it to the
        // lowest; this call moves it to the highest.
        app(ReserveNodeCapacity::class)->execute($middle, $this->resources(), storageId: $pools[(string) $middle->id]->id, reservationKey: 'job-1');

        // The other worker's move, whole: the row and the counters it moves.
        // It lands only if this call reads the row without a lock first.
        $moved = false;
        DB::listen(function ($query) use (&$moved, $low, $middle, $pools): void {
            $sql = strtolower($query->sql);

            if ($moved || ! str_contains($sql, 'from "node_capacity_reservations"') || str_contains($sql, 'for update')) {
                return;
            }

            $moved = true;
            // The other worker's statements are not this call's locks.
            $this->offTheRecord = true;
            DB::table('node_capacity_reservations')->where('reservation_key', 'job-1')->whereNull('released_at')
                ->update(['node_id' => $low->id, 'storage_id' => $pools[(string) $low->id]->id]);
            DB::table('compute_nodes')->where('id', $low->id)->update(['vm_count' => 1, 'allocated_cpu_cores' => 2, 'allocated_memory_mib' => 4096, 'allocated_storage_gib' => 40]);
            DB::table('compute_nodes')->where('id', $middle->id)->update(['vm_count' => 0, 'allocated_cpu_cores' => 0, 'allocated_memory_mib' => 0, 'allocated_storage_gib' => 0]);
            DB::table('compute_storages')->where('id', $pools[(string) $low->id]->id)->update(['committed_gib' => 40]);
            DB::table('compute_storages')->where('id', $pools[(string) $middle->id]->id)->update(['committed_gib' => 0]);
            $this->offTheRecord = false;
        });

        $order = $this->firstLocks(fn () => app(ReserveNodeCapacity::class)
            ->execute($high, $this->resources(), storageId: $pools[(string) $high->id]->id, reservationKey: 'job-1'));
        $moved = true; // The other worker is done; the reads below are this test's.

        $this->assertOrdered($order);
        $this->assertSame((string) $high->id, NodeCapacityReservation::query()->whereNull('released_at')->sole()->node_id);
        $this->assertSame(0, (int) $low->fresh()->vm_count);
        $this->assertSame(0, (int) $middle->fresh()->vm_count);
        $this->assertSame(1, (int) $high->fresh()->vm_count);
        $this->assertSame(0, (int) $pools[(string) $low->id]->fresh()->committed_gib);
        $this->assertSame(0, (int) $pools[(string) $middle->id]->fresh()->committed_gib);
        $this->assertSame(40, (int) $pools[(string) $high->id]->fresh()->committed_gib);
    }

    private function assertMoveIsOrdered(ComputeNode $from, ComputeNode $to): void
    {
        // The destination's pool is created first, so it has the lower id:
        // a move taking the pool it holds before the one it goes to is out
        // of order.
        $toPool = $this->poolOn($to);
        $fromPool = $this->poolOn($from);

        app(ReserveNodeCapacity::class)->execute($from, $this->resources(), storageId: $fromPool->id, reservationKey: 'job-1');

        $order = $this->firstLocks(fn () => app(ReserveNodeCapacity::class)
            ->execute($to, $this->resources(), storageId: $toPool->id, reservationKey: 'job-1'));

        $this->assertOrdered($order);
        $this->assertSame('node_capacity_reservations', $order[0][0], 'The reservation row is not the first thing a move locks.');
        $this->assertCount(2, $this->idsOf($order, 'compute_nodes'), 'A move did not lock both nodes.');
        $this->assertCount(2, $this->idsOf($order, 'compute_storages'), 'A move did not lock both pools.');

        // And it moved.
        $this->assertSame(0, (int) $from->fresh()->vm_count);
        $this->assertSame(1, (int) $to->fresh()->vm_count);
        $this->assertSame(0, (int) $fromPool->fresh()->committed_gib);
        $this->assertSame(40, (int) $toPool->fresh()->committed_gib);
    }

    /**
     * Reservation rows first, then every node row before any storage row,
     * each kind ascending by id.
     *
     * @param  list<array{0: string, 1: ?string}>  $order
     */
    private function assertOrdered(array $order): void
    {
        $rank = ['node_capacity_reservations' => 0, 'compute_nodes' => 1, 'compute_storages' => 2];
        $ranks = array_map(static fn (array $lock): int => $rank[$lock[0]] ?? 9, $order);
        $sorted = $ranks;
        sort($sorted);

        $this->assertSame($sorted, $ranks, 'Locks taken out of order: '.json_encode($order));

        foreach (['compute_nodes', 'compute_storages'] as $table) {
            $ids = $this->idsOf($order, $table);
            $ascending = $ids;
            sort($ascending, SORT_STRING);
            $this->assertSame($ascending, $ids, $table.' rows locked out of id order: '.json_encode($order));
        }
    }

    /**
     * The first lock on each row, in the order the statements ran, as
     * [table, id] (id null where the statement is not by id). A lock is a
     * `for update` select, or an UPDATE or DELETE, which takes the row's lock
     * as it writes: the sync's deadlock was made of UPDATEs alone. An
     * INSERT is not counted, because a row nobody else can see yet is
     * nobody else's to wait for.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private function firstLocks(callable $work): array
    {
        $seen = [];
        $order = [];
        $listening = true;

        DB::listen(function ($query) use (&$seen, &$order, &$listening): void {
            if (! $listening || $this->offTheRecord) {
                return;
            }

            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update') && preg_match('/from\s+"([a-z_]+)"/', $sql, $m) === 1) {
                $table = $m[1];
                $ids = preg_match('/"'.$table.'"\."id"\s*(=|in)/', $sql) === 1
                    ? array_map('strval', $query->bindings)
                    : [null];
            } elseif (preg_match('/^\s*(?:update|delete\s+from)\s+"([a-z_]+)"/', $sql, $m) === 1) {
                $table = $m[1];

                // A row of a table already locked by another key (the
                // reservation, locked by its reservation key and then
                // stamped by id) is taken to be the row that was locked.
                if (isset($seen[$table.'#*'])) {
                    return;
                }

                // Written by id, the id is the statement's last binding.
                $ids = preg_match('/where\s+"(?:'.$table.'"\.")?id"\s*=\s*\?\s*$/', $sql) === 1
                    ? [(string) end($query->bindings)]
                    : [null];
            } else {
                return;
            }

            foreach ($ids as $id) {
                $key = $table.'#'.($id ?? '*');

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $order[] = [$table, $id];
            }
        });

        try {
            $work();
        } finally {
            $listening = false;
        }

        return $order;
    }

    /**
     * @param  list<array{0: string, 1: ?string}>  $order
     * @return list<string>
     */
    private function idsOf(array $order, string $table): array
    {
        return array_values(array_map(
            static fn (array $lock): string => (string) $lock[1],
            array_filter($order, static fn (array $lock): bool => $lock[0] === $table && $lock[1] !== null),
        ));
    }

    /**
     * @return array{0: ComputeNode, 1: ComputeNode}
     */
    private function twoNodesInIdOrder(): array
    {
        $nodes = [];

        foreach (['pve-01', 'pve-02'] as $name) {
            $nodes[] = ComputeNode::factory()->create([
                'cluster_id' => $this->cluster->id,
                'provider_name' => $name,
                'cpu_cores' => 64,
                'memory_mib' => 262144,
                'storage_gib' => 4096,
            ]);
        }

        usort($nodes, static fn (ComputeNode $a, ComputeNode $b): int => strcmp((string) $a->id, (string) $b->id));

        return [$nodes[0], $nodes[1]];
    }

    /**
     * Two nodes, each with a pool of its own, and one pool both share, as
     * the fake cluster reports them - in id order, or against it.
     */
    private function reportTheFleet(bool $reversed, int $pass): void
    {
        $this->cluster->forceFill(['driver' => 'fake'])->save();

        $fleet = [];

        foreach (['pve-01', 'pve-02'] as $name) {
            $fleet[] = [
                'name' => $name,
                'online' => true,
                'cpu_cores' => 32,
                'memory_total_mib' => 262144,
                'memory_used_mib' => 1000 + $pass,
                'storages' => [
                    ['name' => 'local-lvm', 'class' => 'nvme', 'shared' => false, 'total_gib' => 2048, 'available_gib' => 1000 + $pass],
                    ['name' => 'ceph-pool', 'class' => 'ceph', 'shared' => true, 'total_gib' => 65536, 'available_gib' => 30000 + $pass],
                ],
            ];
        }

        config(['compute.fake.nodes' => $reversed ? array_reverse($fleet) : $fleet]);
    }

    private function poolOn(ComputeNode $node): ComputeStorage
    {
        return ComputeStorage::factory()->onNode($node)->create(['total_gib' => 1000, 'available_gib' => 1000]);
    }

    private function resources(): VmResources
    {
        return new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 40);
    }
}
