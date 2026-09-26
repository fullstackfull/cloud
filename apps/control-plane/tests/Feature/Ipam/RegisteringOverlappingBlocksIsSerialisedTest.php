<?php

declare(strict_types=1);

namespace Tests\Feature\Ipam;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Actions\RegisterSubnet;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\SubnetRegistrationRefused;
use Lynomia\Modules\Ipam\Domain\Enums\IpPoolScope;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Two operators registering overlapping blocks at the same moment.
 *
 * The overlap guard reads the estate and then writes to it. Two registrations
 * that both read before either writes would each see an estate without the
 * other's block, both pass, and both commit — the harm the guard exists to
 * prevent, arriving through the gap between its own read and write. The
 * registration therefore takes one advisory lock first, and a second
 * registration waits for the first to finish before it reads anything.
 *
 * ---------------------------------------------------------------------------
 * How the race is made exact
 * ---------------------------------------------------------------------------
 *
 * Operator A opens a transaction on the default connection and registers a
 * block. That is frozen mid-transaction: A's row is written and invisible, and
 * A's lock is held. Operator B then registers on a genuinely separate
 * connection. If B is serialised behind A it waits on the lock, and a
 * lock_timeout turns that wait into an error this test can see instead of a
 * hang. If B is not serialised it reads past A, finds nothing, and commits —
 * which is the failure.
 *
 * That transaction is the test's, and a caller's transaction would hold the
 * lock whether or not the registration takes it inside its own. HTTP callers
 * have none, so one row freezes A another way: with nothing around it, B is
 * run from a query listener at the point where A has read the estate and not
 * yet written.
 *
 * One lock for the whole platform, not one per building: whether two blocks
 * may coexist is decided across buildings as well as within one, so a key
 * scoped to the datacenter would let two buildings race each other on public
 * space. The second test is that race.
 *
 * ---------------------------------------------------------------------------
 * Why this file cannot use RefreshDatabase, and how it cleans up
 * ---------------------------------------------------------------------------
 *
 * RefreshDatabase wraps a test in one transaction on one connection. The
 * second connection could not see the fixtures, and two statements on one
 * connection can never race. Rows are committed for real.
 *
 * They are removed by emptying every table but `migrations`, not by a list.
 * An earlier version of this file kept a hand-written list of the tables it
 * wrote, left out `users` while its setUp committed an operator per test, and
 * the leaked rows turned an unrelated row red in whatever ran after it. A
 * registration writes a subnet, an audit entry and whatever those reach; a
 * list has to be right about all of that for ever, and a truncate does not.
 */
final class RegisteringOverlappingBlocksIsSerialisedTest extends TestCase
{
    private const string SECOND_CONNECTION = 'registrar_b';

    /** SQLSTATE lock_not_available: what a wait that exceeded lock_timeout raises. */
    private const string LOCK_NOT_AVAILABLE = '55P03';

    private string $defaultConnection;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConnection = (string) config('database.default');
        $this->emptyTheCommittedDatabase();

        config([
            'database.connections.'.self::SECOND_CONNECTION => config(
                'database.connections.'.$this->defaultConnection,
            ),
        ]);

        // Serialised means B waits. This turns the wait into an error rather
        // than a suite that never returns.
        DB::connection(self::SECOND_CONNECTION)->statement("SET lock_timeout = '2s'");

        // A never waits when the lock is what it should be. It is bounded too,
        // so that a lock which outlives its transaction — a session lock left
        // on a connection from an earlier test — fails here instead of hanging.
        DB::connection($this->defaultConnection)->statement("SET lock_timeout = '10s'");

        $this->operator = User::factory()->create();
    }

    protected function tearDown(): void
    {
        while (DB::connection($this->defaultConnection)->transactionLevel() > 0) {
            DB::connection($this->defaultConnection)->rollBack();
        }

        DB::purge(self::SECOND_CONNECTION);
        $this->emptyTheCommittedDatabase();
        DB::connection($this->defaultConnection)->statement('RESET lock_timeout');

        parent::tearDown();
    }

    #[Test]
    public function a_second_registration_in_the_same_building_waits_for_the_first_rather_than_reading_past_it(): void
    {
        $site = $this->datacenter('kw-north');

        $this->assertTheSecondWaitsAndIsThenRefused(
            first: [$this->pool($site, 'north-public'), '203.0.113.0/24'],
            second: [$this->pool($site, 'north-reserve'), '203.0.113.0/25'],
        );
    }

    #[Test]
    public function two_buildings_wait_on_the_same_lock(): void
    {
        $this->assertTheSecondWaitsAndIsThenRefused(
            first: [$this->pool($this->datacenter('kw-north'), 'north-public'), '198.51.100.0/24'],
            second: [$this->pool($this->datacenter('kw-south'), 'south-public'), '198.51.100.0/25'],
        );
    }

    #[Test]
    public function the_lock_is_held_by_the_registrations_own_transaction_when_its_caller_has_none(): void
    {
        /*
         * Two HTTP requests arrive with no transaction around either. The two
         * rows above hold A inside a transaction the test opened, and that
         * transaction would keep holding a transaction-scoped lock even if the
         * registration took it outside its own — before the transaction the
         * write commits in, where on a bare connection it is released as soon
         * as its statement ends. So here A has no transaction of the test's
         * around it, and B is run at the one moment that matters: after A has
         * read the estate and before A has written to it, from inside A.
         */
        $site = $this->datacenter('kw-north');
        $firstPool = $this->pool($site, 'north-public');
        $secondPool = $this->pool($site, 'north-reserve');

        $this->assertSame(0, DB::connection($this->defaultConnection)->transactionLevel(), 'A must start with no transaction around it.');

        $b = null;

        DB::listen(function (QueryExecuted $query) use (&$b, $secondPool): void {
            $sql = strtolower($query->sql);

            if ($b !== null
                || $query->connectionName !== $this->defaultConnection
                || ! str_starts_with(ltrim($sql), 'select')
                || ! str_contains($sql, 'from "subnets"')) {
                return;
            }

            // A has read the estate and not yet written. B tries now.
            try {
                $this->asOperatorB(
                    fn (): Subnet => app(RegisterSubnet::class)->execute($secondPool, '203.0.113.0/25', null, null, $this->operator),
                );
                $b = 'committed';
            } catch (QueryException $e) {
                $b = (string) $e->getCode();
            } catch (SubnetRegistrationRefused) {
                $b = 'refused';
            }
        });

        app(RegisterSubnet::class)->execute($firstPool, '203.0.113.0/24', null, null, $this->operator);

        $this->assertSame(
            self::LOCK_NOT_AVAILABLE,
            $b,
            'B was not made to wait while A stood between its read and its write: A no longer held the lock there.',
        );
        $this->assertSame(['203.0.113.0/24'], Subnet::query()->pluck('cidr')->all());
    }

    #[Test]
    public function a_refused_registration_does_not_keep_the_lock(): void
    {
        $site = $this->datacenter('kw-north');
        $held = $this->pool($site, 'north-public');

        app(RegisterSubnet::class)->execute($held, '203.0.113.0/24', null, null, $this->operator);

        try {
            app(RegisterSubnet::class)->execute($held, '203.0.113.0/25', null, null, $this->operator);
            $this->fail('The overlapping block was accepted.');
        } catch (SubnetRegistrationRefused) {
            // Refused, as it should be. What matters is what it left behind.
        }

        // A lock that outlived the refusal would hold every later
        // registration on the platform behind a connection that has moved on.
        $subnet = $this->asOperatorB(
            fn (): Subnet => app(RegisterSubnet::class)->execute(
                $this->pool($this->datacenter('kw-south'), 'south-public'),
                '198.51.100.0/24',
                null,
                null,
                $this->operator,
            ),
        );

        $this->assertSame('198.51.100.0/24', $subnet->cidr);
    }

    #[Test]
    public function the_lock_is_taken_before_the_estate_is_read(): void
    {
        /*
         * Read-then-lock cannot be told apart from lock-then-read through two
         * connections: the read and the lock are one PHP call apart, with no
         * point at which a test could make the other operator run between
         * them. So the order is asserted on the statements themselves.
         */
        $pool = $this->pool($this->datacenter('kw-north'), 'north-public');

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        app(RegisterSubnet::class)->execute($pool, '203.0.113.0/24', null, null, $this->operator);

        $locks = array_keys(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock')));
        $reads = array_keys(array_filter(
            $statements,
            static fn (string $sql): bool => str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'from "subnets"'),
        ));

        $this->assertCount(1, $locks, 'The registration lock was not taken exactly once.');
        $this->assertNotSame([], $reads, 'The registration never read the estate it is guarding.');
        $this->assertLessThan(
            min($reads),
            $locks[0],
            'The estate was read before the lock was taken, so a second registration can read the same estate.',
        );
    }

    /**
     * @param  array{IpPool, string}  $first
     * @param  array{IpPool, string}  $second
     */
    private function assertTheSecondWaitsAndIsThenRefused(array $first, array $second): void
    {
        [$firstPool, $firstBlock] = $first;
        [$secondPool, $secondBlock] = $second;

        // A registers and is frozen before it commits.
        DB::connection($this->defaultConnection)->beginTransaction();
        app(RegisterSubnet::class)->execute($firstPool, $firstBlock, null, null, $this->operator);

        try {
            $this->asOperatorB(
                fn (): Subnet => app(RegisterSubnet::class)->execute($secondPool, $secondBlock, null, null, $this->operator),
            );

            $this->fail(sprintf(
                'B registered %s while A held %s uncommitted: the second registration read past the first.',
                $secondBlock,
                $firstBlock,
            ));
        } catch (QueryException $e) {
            $this->assertSame(
                self::LOCK_NOT_AVAILABLE,
                $e->getCode(),
                'B failed, but not by waiting for A: '.$e->getMessage(),
            );
        }

        DB::connection($this->defaultConnection)->commit();

        // Now that A has finished, B's retry sees A's block and is refused.
        try {
            $this->asOperatorB(
                fn (): Subnet => app(RegisterSubnet::class)->execute($secondPool, $secondBlock, null, null, $this->operator),
            );

            $this->fail('Once A had committed, B was still allowed to register an overlapping block.');
        } catch (SubnetRegistrationRefused $e) {
            $this->assertStringContainsString($firstBlock, $e->getMessage());
        }

        $this->assertSame([$firstBlock], Subnet::query()->pluck('cidr')->all());
    }

    private function datacenter(string $slug): string
    {
        return (string) Datacenter::factory()->create(['slug' => $slug])->id;
    }

    private function pool(string $datacenterId, string $slug): IpPool
    {
        return IpPool::factory()->create([
            'datacenter_id' => $datacenterId,
            'slug' => $slug,
            'scope' => IpPoolScope::Public,
        ]);
    }

    /**
     * Run a closure as the other operator: its own connection, its own
     * transaction, its own locks. A's open transaction belongs to the
     * connection it began on and is untouched by the swap.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operator
     * @return TReturn
     */
    private function asOperatorB(callable $operator): mixed
    {
        DB::setDefaultConnection(self::SECOND_CONNECTION);

        try {
            return $operator();
        } finally {
            DB::setDefaultConnection($this->defaultConnection);
        }
    }

    /**
     * Every table but `migrations`, on a database that can only be a test one.
     *
     * The same refusal WorkerHarness makes before its own truncate, for the
     * same reason: this runs automatically, and it must not be able to run
     * against a database somebody cares about because an environment file
     * was copied and never repointed.
     */
    private function emptyTheCommittedDatabase(): void
    {
        $connection = DB::connection($this->defaultConnection);
        $database = (string) $connection->getDatabaseName();

        if (! app()->environment('testing')
            || $database !== (string) config('database.connections.pgsql.database')
            || ! str_contains(strtolower($database), 'test')) {
            throw new RuntimeException(sprintf(
                'Refusing to empty "%s": this test only ever empties the configured test database.',
                $database,
            ));
        }

        /** @var list<object{tablename: string}> $tables */
        $tables = $connection->select(
            "select tablename from pg_tables where schemaname = current_schema() and tablename <> 'migrations'",
        );

        if ($tables === []) {
            return;
        }

        $connection->statement('truncate '.implode(', ', array_map(
            static fn (object $table): string => '"'.str_replace('"', '""', $table->tablename).'"',
            $tables,
        )).' cascade');
    }
}
