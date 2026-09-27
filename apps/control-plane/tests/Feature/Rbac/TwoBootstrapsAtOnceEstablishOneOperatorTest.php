<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * Concurrent `operator:bootstrap` runs establish one super admin (OB-2,
 * re-audit of round three).
 *
 * Measured at 88c4dd8: the command counted super admins before any
 * transaction or lock, so two concurrent runs on a fresh deployment each saw
 * none and each created one — two super admins in four of six rounds, against
 * a docblock that says the command is "single use, by state".
 *
 * ---------------------------------------------------------------------------
 * How the race is made exact rather than likely
 * ---------------------------------------------------------------------------
 *
 * The racers are separate PHP processes (bootstrap_racer.php), each with its
 * own connection. Before they start, a holding connection takes the `users`
 * table in SHARE mode: reads pass, the INSERT that creates an operator waits.
 * The test waits until every racer is stopped on a lock, then lets go.
 *
 *  - Unserialised, every racer has already counted zero super admins by the
 *    time it stops on the INSERT, so every one of them creates one.
 *  - Serialised, the first racer holds the bootstrap lock while it waits on
 *    the INSERT, and the others wait on that lock before counting. When the
 *    first commits they count one and refuse.
 *
 * Both ends are deterministic; nothing depends on scheduling luck. The
 * addresses differ, so the unique index on `users.email` cannot make the
 * count right by accident.
 *
 * The fixtures are committed, because the racers cannot see this test's
 * transaction; LeavesNothingCommitted empties every table afterwards.
 */
final class TwoBootstrapsAtOnceEstablishOneOperatorTest extends TestCase
{
    use LeavesNothingCommitted;
    use RefreshDatabase;

    private const string HOLD = 'pgsql_users_hold';

    private const int RACERS = 3;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        if (config('database.connections.'.self::HOLD) !== null) {
            while (DB::connection(self::HOLD)->transactionLevel() > 0) {
                DB::connection(self::HOLD)->rollBack();
            }

            DB::purge(self::HOLD);
        }

        $this->emptyEveryTable();

        parent::tearDown();
    }

    #[Test]
    public function concurrent_bootstraps_on_a_fresh_deployment_make_exactly_one_super_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->assertSame(0, User::query()->count(), 'Precondition: a fresh deployment.');

        $results = $this->race(array_map(
            static fn (int $i): string => sprintf('first-%d@lynomia.test', $i),
            range(1, self::RACERS),
        ));

        $this->assertCount(self::RACERS, $results, 'Every racer must have reported an outcome.');

        foreach ($results as $result) {
            $this->assertNull($result['error'], 'A racer crashed rather than deciding: '.$result['error']);
        }

        $this->assertSame(
            1,
            User::query()->role(Role::SuperAdmin->value)->count(),
            'Concurrent bootstraps each established a super admin.',
        );
        $this->assertSame(1, User::query()->count(), 'A refused bootstrap left an account behind.');
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::OperatorBootstrapped)->count());

        // Refused by the rule — exit 1 — not by a crash, a deadlock or a
        // timeout that would make the count right by accident.
        $exits = array_map(static fn (array $r): ?int => $r['exit'], $results);
        sort($exits);
        $this->assertSame([0, ...array_fill(0, self::RACERS - 1, 1)], $exits);
    }

    /**
     * @param  list<string>  $emails
     * @return list<array{exit: ?int, error: ?string}>
     */
    private function race(array $emails): array
    {
        config(['database.connections.'.self::HOLD => config('database.connections.'.config('database.default'))]);
        DB::connection(self::HOLD)->beginTransaction();
        DB::connection(self::HOLD)->statement('LOCK TABLE users IN SHARE MODE');

        /** @var list<Process> $processes */
        $processes = [];

        foreach ($emails as $email) {
            $process = new Process(
                ['php', __DIR__.'/bootstrap_racer.php', $email],
                base_path(),
                ['APP_ENV' => 'testing'],
                null,
                60.0,
            );

            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 45.0;

        // Every racer stopped on a lock: on the held table (INSERT), or on
        // the bootstrap lock another racer holds.
        while ((int) DB::scalar(
            "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'"
        ) < count($emails)) {
            if (microtime(true) > $deadline) {
                foreach ($processes as $process) {
                    $process->stop(0);
                }

                DB::connection(self::HOLD)->rollBack();
                $this->fail('The racers never all stopped on a lock, so nothing was raced.');
            }

            usleep(20_000);
        }

        DB::connection(self::HOLD)->rollBack();

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            $line = trim($process->getOutput());
            $this->assertNotSame('', $line, 'A racer produced no verdict: '.$process->getErrorOutput());

            /** @var array{exit: ?int, error: ?string} $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $results[] = $decoded;
        }

        return $results;
    }
}
