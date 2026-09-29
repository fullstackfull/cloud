<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\ReleaseNodeCapacity;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
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
 * Checked from the statements actually issued, in the order they ran, and
 * from the FIRST lock on each row only: a row locked again later in the same
 * transaction is already held and waits for nothing. A single process cannot
 * make the deadlock happen, so this asserts the order that prevents it (as
 * MoneyPathsTakeTheirLocksInOneOrderTest does for the money paths).
 */
final class CapacityPathsTakeTheirLocksInOneOrderTest extends TestCase
{
    use RefreshDatabase;

    private ComputeCluster $cluster;

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
     * The first `for update` on each row, in the order the statements ran, as
     * [table, id] (id null where the statement is not by id).
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private function firstLocks(callable $work): array
    {
        $seen = [];
        $order = [];

        DB::listen(static function ($query) use (&$seen, &$order): void {
            $sql = strtolower($query->sql);

            if (! str_contains($sql, 'for update') || preg_match('/from\s+"([a-z_]+)"/', $sql, $m) !== 1) {
                return;
            }

            $table = $m[1];
            $ids = preg_match('/"'.$table.'"\."id"\s*(=|in)/', $sql) === 1
                ? array_map('strval', $query->bindings)
                : [null];

            foreach ($ids as $id) {
                $key = $table.'#'.($id ?? '*');

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $order[] = [$table, $id];
            }
        });

        $work();

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

    private function poolOn(ComputeNode $node): ComputeStorage
    {
        return ComputeStorage::factory()->onNode($node)->create(['total_gib' => 1000, 'available_gib' => 1000]);
    }

    private function resources(): VmResources
    {
        return new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 40);
    }
}
