<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Application\Actions\SyncClusterInventory;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\Support\RedisIndexForThisRun;
use Tests\TestCase;

/**
 * An inventory sync and a reservation that meet on a node and a shared pool
 * both finish (D1, round six).
 *
 * The sync wrote each node and then its pools, in the order the cluster
 * reported them, in one transaction: node A, the shared pool, node B. A
 * reservation on node B locks node B, then the pool. Each held what the other
 * waited for, and PostgreSQL killed one - 3 times out of 3 in the re-audit,
 * either way round: an order's reservation lost an attempt, or the sync pass
 * failed without a word in last_sync_error.
 *
 * Shown with two processes, deterministically. The reservation's first step
 * (the lock on node B) is taken here inside an open transaction; the sync runs
 * in another process ({@see inventory_sync_racer.php}) until it is seen
 * waiting on a lock in `pg_stat_activity` (not a sleep); then the reservation
 * goes on to the pool. A sync that holds the pool while it waits for node B
 * deadlocks here; one that takes every node before any pool is waiting on
 * node B holding no pool, and both complete.
 *
 * Every table is emptied afterwards (LeavesNothingCommitted).
 */
final class AnInventorySyncDoesNotDeadlockAReservationTest extends TestCase
{
    use LeavesNothingCommitted;

    #[Test]
    public function a_sync_and_a_reservation_on_a_shared_pool_both_finish(): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'These rows must be committed for another process to see them.');

        $cluster = ComputeCluster::factory()->create(['driver' => 'fake']);
        config(['compute.fake.nodes' => $this->fleet(0)]);
        app(SyncClusterInventory::class)->execute($cluster);
        ComputeNode::query()->update(['status' => NodeStatus::Active->value, 'is_healthy' => true]);

        // Node B is the one the cluster reports second, after the pool.
        $nodeB = ComputeNode::query()->where('provider_name', 'pve-02')->sole();
        $pool = ComputeStorage::query()->where('shared', true)->sole();

        $reserveError = null;
        DB::beginTransaction();

        try {
            ComputeNode::query()->lockForUpdate()->findOrFail($nodeB->id);

            $sync = $this->syncInAnotherProcess($cluster);
            $this->assertTrue($this->untilBlockedOrFinished($sync), 'The sync never waited on the node the reservation holds: '.$sync->getErrorOutput());

            try {
                app(ReserveNodeCapacity::class)->execute($nodeB, new VmResources(2, 4096, 40), storageId: $pool->id, reservationKey: 'race-1');
            } catch (QueryException $e) {
                $reserveError = $e;
            }
        } finally {
            $reserveError === null ? DB::commit() : DB::rollBack();
        }

        $sync->wait();
        $line = trim($sync->getOutput());
        $this->assertNotSame('', $line, 'The sync produced no verdict: '.$sync->getErrorOutput());

        /** @var array{completed: bool, sqlstate: ?string, error: ?string} $outcome */
        $outcome = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull($reserveError, 'The reservation was killed: '.($reserveError?->getMessage() ?? ''));
        $this->assertTrue($outcome['completed'], 'The sync was killed: '.$line);
        $this->assertNull($cluster->refresh()->last_sync_error);
        $this->assertSame(1, (int) $nodeB->refresh()->vm_count);
        $this->assertSame(40, (int) $pool->refresh()->committed_gib);
        $this->assertSame(30001, (int) $pool->available_gib, 'The sync did not write the pool.');
    }

    /**
     * Whether the other process was seen waiting on a lock (true) or had
     * already finished (false).
     */
    private function untilBlockedOrFinished(Process $process): bool
    {
        $deadline = microtime(true) + 45.0;

        while ($process->isRunning()) {
            // Inside this transaction the statistics views are a snapshot
            // unless it is dropped.
            DB::select('SELECT pg_stat_clear_snapshot()');

            $waiting = (int) DB::scalar(
                "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'"
            );

            if ($waiting > 0) {
                return true;
            }

            if (microtime(true) > $deadline) {
                $process->stop(0);
                $this->fail('The sync neither waited nor finished: '.$process->getErrorOutput());
            }

            usleep(20_000);
        }

        return false;
    }

    private function syncInAnotherProcess(ComputeCluster $cluster): Process
    {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/inventory_sync_racer.php', (string) $cluster->id, json_encode($this->fleet(1), JSON_THROW_ON_ERROR)],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
                'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
                'REDIS_DB' => (string) RedisIndexForThisRun::resolve(),
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
            ],
            null,
            60.0,
        );

        $process->start();

        return $process;
    }

    /**
     * Two nodes and one Ceph pool both see, as the cluster reports them. The
     * figures move with each pass, so the second pass writes every row.
     *
     * @return list<array<string, mixed>>
     */
    private function fleet(int $pass): array
    {
        $fleet = [];

        foreach (['pve-01', 'pve-02'] as $name) {
            $fleet[] = [
                'name' => $name,
                'online' => true,
                'cpu_cores' => 32,
                'memory_total_mib' => 262144,
                'memory_used_mib' => 1000 + $pass,
                'storages' => [
                    ['name' => 'ceph-pool', 'class' => 'ceph', 'shared' => true, 'total_gib' => 65536, 'available_gib' => 30000 + $pass],
                ],
            ];
        }

        return $fleet;
    }
}
