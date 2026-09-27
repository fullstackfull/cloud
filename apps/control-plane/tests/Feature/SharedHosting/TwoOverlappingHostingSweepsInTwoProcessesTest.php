<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHosting;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\Support\RedisIndexForThisRun;
use Tests\TestCase;

/**
 * Two `hosting:reconcile` sweeps overlapping on one node, each in its own
 * process, do not deadlock each other.
 *
 * Each drift RecordDrift writes is serialised on a transaction-scoped advisory
 * lock, and inside the node's transaction that lock is held until the node
 * commits. Two sweeps that read the panel's listing in different orders took
 * those locks in different orders: A held the lock for `strangerone` and
 * wanted `strangertwo`, B held `strangertwo` and wanted `strangerone`, and
 * PostgreSQL broke the cycle by failing one of them, whose node was then
 * stamped with a DeadlockException and "nothing was concluded".
 *
 * Here the order is forced: each sweep is `hosting:reconcile` in a process of
 * its own (tests/Support/Processes/reconcile-hosting-paused-after-its-first-drift-lock.php),
 * held still just after its first drift lock was granted. A goes first; B is
 * started and let run until it has either taken its own first drift lock or is
 * waiting on something other than the test's gate; then both are let go.
 *
 * What must hold: both runs succeed, the node carries no error, and each
 * stranger is one drift row seen twice.
 */
final class TwoOverlappingHostingSweepsInTwoProcessesTest extends TestCase
{
    use LeavesNothingCommitted;

    private const string NODE = 'overlap-node';

    #[Test]
    public function two_sweeps_that_read_the_listing_in_opposite_orders_both_conclude(): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'These rows must be committed for another process to see them.');

        $node = HostingNode::factory()->create([
            'slug' => self::NODE,
            'hostname' => self::NODE.'.lynomia.test',
            'panel' => HostingPanel::DirectAdmin,
            'api_endpoint' => 'https://'.self::NODE.'.lynomia.test:2222',
            'credentials_reference' => self::NODE,
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
        ]);

        $gate = 7_400_000 + random_int(1, 99_999);
        DB::select('select pg_advisory_lock(?)', [$gate]);

        try {
            $sweepA = $this->sweep('list[]=strangerone&list[]=strangertwo', $gate);
            $sweepA->start();
            $this->waitFor(fn (): bool => str_contains($sweepA->getErrorOutput(), 'LOCKED'), $sweepA, 'Sweep A never took its first drift lock');

            $sweepB = $this->sweep('list[]=strangertwo&list[]=strangerone', $gate);
            $sweepB->start();
            $this->waitFor(
                fn (): bool => str_contains($sweepB->getErrorOutput(), 'LOCKED') || $this->sweepWaitsOnSomethingButTheGate($gate, $sweepA, $sweepB),
                $sweepB,
                'Sweep B neither took a drift lock nor waited on anything',
            );
        } finally {
            DB::select('select pg_advisory_unlock(?)', [$gate]);
        }

        $sweepA->wait();
        $sweepB->wait();

        $report = 'A: '.$sweepA->getOutput().$sweepA->getErrorOutput()."\nB: ".$sweepB->getOutput().$sweepB->getErrorOutput()
            ."\nnode: ".json_encode($node->fresh()?->reconcile_error);

        $this->assertSame(0, $sweepA->getExitCode(), $report);
        $this->assertSame(0, $sweepB->getExitCode(), $report);
        $this->assertStringContainsString('1 nodes checked', $sweepA->getOutput(), $report);
        $this->assertStringContainsString('1 nodes checked', $sweepB->getOutput(), $report);
        $this->assertNull($node->fresh()?->reconcile_error, $report);

        $drifts = ResourceDrift::query()->orderBy('provider_reference')->get();
        $this->assertSame(['strangerone', 'strangertwo'], $drifts->pluck('provider_reference')->all());
        $this->assertSame([2, 2], $drifts->pluck('occurrences')->all());
    }

    /**
     * Whether a sweep process's backend is waiting for a lock other than the
     * test's gate (which sweep A, and a sweep that has taken its first drift
     * lock, wait on).
     *
     * Only the sweeps' own backends count: pg_locks covers the whole cluster,
     * and a lock another run waits on, in this database or another, must not
     * let sweep B go early. Each sweep names its backend on standard error
     * (`BACKEND <pid>`) before it runs the command. Filtered by backend and
     * not by pg_locks.database, which is null for the transaction-id lock a
     * row-lock waiter waits on.
     */
    private function sweepWaitsOnSomethingButTheGate(int $gate, Process ...$sweeps): bool
    {
        $pids = [];

        foreach ($sweeps as $sweep) {
            if (preg_match('/^BACKEND (\d+)$/m', $sweep->getErrorOutput(), $m) === 1) {
                $pids[] = (int) $m[1];
            }
        }

        if ($pids === []) {
            return false;
        }

        $row = DB::selectOne(
            sprintf(
                "select count(*) as waiting from pg_locks
                  where not granted
                    and pid in (%s)
                    and not (locktype = 'advisory' and classid::bigint = 0 and objid::bigint = ? and objsubid = 1)",
                implode(', ', $pids),
            ),
            [$gate],
        );

        return (int) $row->waiting > 0;
    }

    private function waitFor(callable $condition, Process $process, string $message): void
    {
        $started = microtime(true);

        while (! $condition() && $process->isRunning() && microtime(true) - $started < 60) {
            usleep(50_000);
        }

        $this->assertTrue($condition(), $message.': '.$process->getErrorOutput().$process->getOutput());
    }

    private function sweep(string $listing, int $gate): Process
    {
        return new Process(
            [PHP_BINARY, base_path('tests/Support/Processes/reconcile-hosting-paused-after-its-first-drift-lock.php')],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
                'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
                'REDIS_DB' => (string) RedisIndexForThisRun::resolve(),
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
                'SWEEP_NODE' => self::NODE,
                'SWEEP_LISTING' => $listing,
                'SWEEP_PAUSE_LOCK' => (string) $gate,
            ],
            null,
            120,
        );
    }
}
