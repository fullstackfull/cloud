<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Application\Actions\InviteOperator;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * Two invitations of one address at once make one operator; the other is
 * refused as the request refuses an operator's address (B8-1, re-audit after
 * round seven).
 *
 * Without the address's advisory lock, each invitation of an address with no
 * login finds no row to lock, each goes on to insert one, and the second
 * fails on the unique index — a 500. Without the check under the lock, the
 * second waits for the first to commit, finds its login and promotes it: the
 * first invitation's roles replaced, its credentials revoked, its reset token
 * deleted.
 *
 * ---------------------------------------------------------------------------
 * How the race is made exact rather than likely
 * ---------------------------------------------------------------------------
 *
 * As in TwoBootstrapsAtOnceEstablishOneOperatorTest: the racers are separate
 * PHP processes (invitation_racer.php), each with its own connection. Before
 * they start, a holding connection takes the `users` table in SHARE mode:
 * reads pass, the INSERT that creates the login waits. The test waits until
 * both racers are stopped on a lock, then lets go.
 *
 *  - Unserialised, both racers have already found no login by the time they
 *    stop on the INSERT; the second's INSERT fails on `users_email_unique`.
 *  - Serialised without the check under the lock, the second waits on the
 *    advisory lock until the first commits, finds its login, and promotes
 *    it: two invitations go through.
 *  - Serialised and checked, the second finds an operator's login and is
 *    refused with the request's own refusal.
 *
 * The fixtures are committed, because the racers cannot see this test's
 * transaction; LeavesNothingCommitted empties every table afterwards.
 */
final class TwoInvitationsOfOneAddressAtOnceMakeOneOperatorTest extends TestCase
{
    use LeavesNothingCommitted;
    use RefreshDatabase;

    private const string HOLD = 'pgsql_users_hold';

    private const string ADDRESS = 'wanted-twice@lynomia.test';

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
    public function two_invitations_of_a_new_address_at_once_make_one_operator_and_refuse_the_other(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $first = User::factory()->create(['email_verified_at' => now()]);
        $first->syncRoles([Role::SuperAdmin->value]);
        $second = User::factory()->create(['email_verified_at' => now()]);
        $second->syncRoles([Role::SuperAdmin->value]);

        $results = $this->race([
            [(string) $first->id, Role::Noc->value],
            [(string) $second->id, Role::InfrastructureAdmin->value],
        ]);

        foreach ($results as $result) {
            $this->assertNull($result['error'], 'A racer crashed rather than deciding: '.$result['error']);
        }

        $invited = array_values(array_filter($results, static fn (array $r): bool => $r['invited']));
        $refused = array_values(array_filter($results, static fn (array $r): bool => ! $r['invited']));
        $this->assertCount(1, $invited, 'Both invitations went through: the second re-roled the first\'s operator.');
        $this->assertCount(1, $refused);
        $this->assertSame(['email' => [InviteOperator::THE_ADDRESS_IS_AN_OPERATORS]], $refused[0]['refusal']);

        $operator = User::query()->where('email', self::ADDRESS)->sole();
        $this->assertCount(1, $operator->getRoleNames());
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::OperatorInvited)->count());
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::OperatorRolesChanged)->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', self::ADDRESS)->count());
    }

    /**
     * @param  list<array{0: string, 1: string}>  $racers  the inviter's id and the role each gives
     * @return list<array{invited: bool, refusal: ?array<string, list<string>>, error: ?string}>
     */
    private function race(array $racers): array
    {
        config(['database.connections.'.self::HOLD => config('database.connections.'.config('database.default'))]);
        DB::connection(self::HOLD)->beginTransaction();
        DB::connection(self::HOLD)->statement('LOCK TABLE users IN SHARE MODE');

        /** @var list<Process> $processes */
        $processes = [];

        foreach ($racers as [$actor, $role]) {
            $process = new Process(
                ['php', __DIR__.'/invitation_racer.php', $actor, self::ADDRESS, $role],
                base_path(),
                ['APP_ENV' => 'testing'],
                null,
                60.0,
            );

            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 45.0;

        // Both racers stopped on a lock: on the held table (INSERT), or on
        // the address's advisory lock the other racer holds.
        while ((int) DB::scalar(
            "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'"
        ) < count($racers)) {
            if (microtime(true) > $deadline) {
                foreach ($processes as $process) {
                    $process->stop(0);
                }

                DB::connection(self::HOLD)->rollBack();
                $this->fail('The racers never both stopped on a lock, so nothing was raced.');
            }

            usleep(20_000);
        }

        DB::connection(self::HOLD)->rollBack();

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            $line = trim($process->getOutput());
            $this->assertNotSame('', $line, 'A racer produced no verdict: '.$process->getErrorOutput());

            /** @var array{invited: bool, refusal: ?array<string, list<string>>, error: ?string} $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $results[] = $decoded;
        }

        return $results;
    }
}
