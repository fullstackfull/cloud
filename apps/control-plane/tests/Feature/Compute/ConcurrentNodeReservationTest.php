<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Compute\Concerns\CreatesComputeFleet;
use Tests\TestCase;

/**
 * Two provisioning workers, two real database connections, one node with room
 * for one machine.
 *
 * This is the test the reservation action exists for. Two orders paid for in
 * the same second are two queue workers on two machines, and scoring
 * deliberately reads without a lock — so both can decide on the same node at
 * the same moment and both be right when they decide. If they can then both
 * commit, the node's committed memory exceeds its physical memory, and the
 * bill for that arrives weeks later as the OOM killer picking a customer's
 * database.
 *
 * It deliberately does not use RefreshDatabase. That trait wraps the whole
 * test in one transaction on one connection, which makes a concurrency test
 * meaningless twice over: the second connection cannot see the fixtures, and
 * two queries issued on a single connection are serialised by definition and
 * can never race. Rows are therefore committed for real and cleaned up again
 * in tearDown.
 *
 * The interleaving is exact rather than hopeful. Worker A opens a transaction
 * and reserves, and is then frozen mid-transaction — its row locked and its
 * write invisible to anybody else — while worker B runs on its own connection.
 */
final class ConcurrentNodeReservationTest extends TestCase
{
    use CreatesComputeFleet;

    private const string SECOND_CONNECTION = 'worker_b';

    private ComputeNode $node;

    private string $defaultConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConnection = (string) config('database.default');
        $this->wipe();

        // A genuinely separate connection to the same database: same
        // credentials, a different PDO handle, and therefore a different
        // transaction and a different set of locks.
        config([
            'database.connections.'.self::SECOND_CONNECTION => config(
                'database.connections.'.$this->defaultConnection,
            ),
        ]);

        /*
         * Worker B will genuinely block on worker A's row lock — that is the
         * point — and because A is frozen inside this single-threaded test the
         * lock would never be released. A short lock timeout turns a hang into
         * a visible, assertable failure.
         */
        DB::connection(self::SECOND_CONNECTION)->statement("SET lock_timeout = '3s'");

        // The threshold is put out of the way so the arithmetic in this test
        // is about the lock, not about policy: 10 GiB usable, and each machine
        // asks for 8 GiB, so exactly one of the two can be right.
        config()->set('compute.scheduler.capacity_threshold_percent', 100);

        $this->node = ComputeNode::factory()->for($this->cluster(), 'cluster')->create([
            'memory_mib' => 10240,
            'memory_headroom_percent' => 0,
            'allocated_memory_mib' => 0,
            'cpu_cores' => 8,
            'storage_gib' => 1024,
        ]);
    }

    protected function tearDown(): void
    {
        // A test that failed mid-transaction must not leave the connection
        // holding locks for the next one.
        while (DB::connection($this->defaultConnection)->transactionLevel() > 0) {
            DB::connection($this->defaultConnection)->rollBack();
        }

        DB::purge(self::SECOND_CONNECTION);
        $this->wipe();

        parent::tearDown();
    }

    #[Test]
    public function two_workers_on_separate_connections_cannot_both_overcommit_the_node(): void
    {
        $machine = new VmResources(vcpu: 2, memoryMib: 8192, diskGib: 100);

        // Worker A opens its transaction and commits the node's memory to its
        // machine. The row lock is held and the write is invisible to everyone
        // else until it commits.
        DB::connection($this->defaultConnection)->beginTransaction();

        app(ReserveNodeCapacity::class)->execute($this->node, $machine);

        try {
            $this->asWorkerB(fn (): ComputeNode => app(ReserveNodeCapacity::class)->execute($this->node, $machine));

            $this->fail('The second worker read and wrote the node while the first was holding it.');
        } catch (QueryException $e) {
            /*
             * B waited for A's lock rather than proceeding, which is the whole
             * mechanism: without SELECT ... FOR UPDATE it would have read the
             * pre-reservation row, decided there was room, and written its own
             * increment over A's.
             */
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        }

        // Only now does A finish. B did not get to write anything while it ran.
        DB::connection($this->defaultConnection)->commit();

        try {
            $this->asWorkerB(fn (): ComputeNode => app(ReserveNodeCapacity::class)->execute($this->node, $machine));

            $this->fail('The node was committed to 16 GiB of machines on 10 GiB of memory.');
        } catch (NodeCapacityExceededException $e) {
            // B's retry now reads the committed row under its own lock and
            // discovers the world moved. This is the re-check earning its
            // keep: B passed scoring against exactly the same node.
            $this->assertSame((string) $this->node->getKey(), $e->context()['node_id']);
        }

        $this->assertSame(8192, $this->freshNode()->allocated_memory_mib);
        $this->assertSame(1, $this->freshNode()->vm_count);
    }

    #[Test]
    public function the_worker_that_commits_first_wins_and_the_second_is_refused(): void
    {
        $machine = new VmResources(vcpu: 2, memoryMib: 8192, diskGib: 100);

        // No open transaction this time: A simply gets there first, which is
        // the ordinary case. The row both workers scored against is identical.
        $stale = $this->freshNode();

        app(ReserveNodeCapacity::class)->execute($stale, $machine);

        $this->expectException(NodeCapacityExceededException::class);

        // B is holding a copy of the node from before A committed — precisely
        // what a job that sat in a queue for a few seconds holds.
        $this->asWorkerB(fn (): ComputeNode => app(ReserveNodeCapacity::class)->execute($stale, $machine));
    }

    /**
     * Run a closure as if it were another worker: its own connection, its own
     * transaction, its own locks. Worker A's open transaction belongs to the
     * connection instance it began on and is untouched by the swap.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $worker
     * @return TReturn
     */
    private function asWorkerB(callable $worker): mixed
    {
        DB::setDefaultConnection(self::SECOND_CONNECTION);

        try {
            return $worker();
        } finally {
            DB::setDefaultConnection($this->defaultConnection);
        }
    }

    private function freshNode(): ComputeNode
    {
        return ComputeNode::on($this->defaultConnection)->findOrFail($this->node->getKey());
    }
}
