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
 * Two `hosting:reconcile` sweeps on two different nodes of one panel type,
 * each in its own process, do not deadlock each other.
 *
 * Locking the node first (TwoOverlappingHostingSweepsInTwoProcessesTest) keeps
 * two sweeps on one node apart. It does nothing for two sweeps on two nodes:
 * RecordDrift's lock is keyed by the panel type and the username, not the
 * node, so a stranger called `strangerone` on node A and one on node B are one
 * lock. Sweep A, on A, held `strangerone` and wanted `strangertwo`; sweep B,
 * on B, whose panel listed them the other way round, held `strangertwo` and
 * wanted `strangerone`; PostgreSQL failed one of them with a deadlock.
 *
 * The sweeps are put on different nodes the way an operator's run beside the
 * scheduled one gets there: each takes one node (HOSTING_RECONCILE_BATCH=1),
 * the least recently attempted when it asks, and node B becomes that after
 * sweep A has chosen A. Then, as in the one-node test: A is held still after
 * its first drift lock, B is let run until it has taken its own first drift
 * lock or is waiting on something other than the test's gate, and both are
 * let go.
 *
 * What must hold: both runs succeed, neither node carries an error, and each
 * stranger's one drift row was seen by both sweeps.
 */
final class TwoSweepsOnTwoNodesOfOnePanelTest extends TestCase
{
    use LeavesNothingCommitted;

    private const string NODE_A = 'pair-node-a';

    private const string NODE_B = 'pair-node-b';

    #[Test]
    public function two_sweeps_on_two_nodes_whose_listings_run_in_opposite_orders_both_conclude(): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'These rows must be committed for another process to see them.');

        $nodeA = $this->node(self::NODE_A, now()->subHours(2));
        $nodeB = $this->node(self::NODE_B, now()->subHour());

        $gate = 7_500_000 + random_int(1, 99_999);
        DB::select('select pg_advisory_lock(?)', [$gate]);

        try {
            $sweepA = $this->sweep('list[]=strangerone&list[]=strangertwo', $gate);
            $sweepA->start();
            $this->waitFor(fn (): bool => str_contains($sweepA->getErrorOutput(), 'LOCKED'), $sweepA, 'Sweep A never took its first drift lock');

            // Sweep A has chosen node A and holds its row. Node B is now the
            // least recently attempted, so a sweep that asks next takes it.
            $nodeB->forceFill(['reconcile_attempted_at' => now()->subHours(3)])->save();

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
            ."\nnode A: ".json_encode($nodeA->fresh()?->reconcile_error)."\nnode B: ".json_encode($nodeB->fresh()?->reconcile_error);

        $this->assertTrue($nodeB->fresh()?->reconciled_at !== null && $nodeA->fresh()?->reconciled_at !== null, 'The sweeps did not cover both nodes. '.$report);
        $this->assertNull($nodeA->fresh()?->reconcile_error, $report);
        $this->assertNull($nodeB->fresh()?->reconcile_error, $report);
        $this->assertSame(0, $sweepA->getExitCode(), $report);
        $this->assertSame(0, $sweepB->getExitCode(), $report);
        $this->assertStringContainsString('1 nodes checked', $sweepA->getOutput(), $report);
        $this->assertStringContainsString('1 nodes checked', $sweepB->getOutput(), $report);

        $drifts = ResourceDrift::query()->orderBy('provider_reference')->get();
        $this->assertSame(['strangerone', 'strangertwo'], $drifts->pluck('provider_reference')->all(), $report);
        $this->assertSame([2, 2], $drifts->pluck('occurrences')->all(), $report);
    }

    private function node(string $slug, mixed $attemptedAt): HostingNode
    {
        return HostingNode::factory()->create([
            'slug' => $slug,
            'hostname' => $slug.'.lynomia.test',
            'panel' => HostingPanel::DirectAdmin,
            'api_endpoint' => 'https://'.$slug.'.lynomia.test:2222',
            'credentials_reference' => $slug,
            'verify_tls' => true,
            'status' => HostingNodeStatus::Active,
            'accepts_new_accounts' => true,
            'panel_licensed' => true,
            'account_count' => 0,
            'reconcile_attempted_at' => $attemptedAt,
        ]);
    }

    /**
     * Whether a sweep process's backend is waiting for a lock other than the
     * test's gate. As in TwoOverlappingHostingSweepsInTwoProcessesTest, which
     * says why only the sweeps' own backends are read.
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
                'HOSTING_RECONCILE_BATCH' => '1',
                'SWEEP_NODE' => self::NODE_A.','.self::NODE_B,
                'SWEEP_LISTING' => $listing,
                'SWEEP_PAUSE_LOCK' => (string) $gate,
            ],
            null,
            120,
        );
    }
}
