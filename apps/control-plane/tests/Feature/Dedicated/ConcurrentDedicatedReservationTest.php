<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Dedicated\Application\Actions\ReserveDedicatedServer;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\NoMatchingHardwareException;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two operators approving two orders in the same minute, on two real database
 * connections, with one machine of the profile in stock.
 *
 * This is the test the reservation action exists for, and physical hardware
 * makes it sharper than its compute equivalent. An oversold hypervisor node
 * can be resolved by moving a machine; one dedicated server promised to two
 * customers cannot be resolved at all. There is one box. The second customer's
 * server has to be un-sold by a human.
 *
 * It deliberately does not use RefreshDatabase. That trait wraps the whole test
 * in one transaction on one connection, which makes a concurrency test
 * meaningless twice over: the second connection cannot see the fixtures, and
 * two queries on a single connection are serialised by definition and can
 * never race. Rows are committed for real and cleaned up in tearDown.
 *
 * The interleaving is exact rather than hopeful. Worker A opens a transaction
 * and reserves, and is then frozen mid-transaction — its row locked and its
 * write invisible to anybody else — while worker B runs on its own connection.
 */
final class ConcurrentDedicatedReservationTest extends TestCase
{
    private const string SECOND_CONNECTION = 'worker_b';

    private const string PROFILE = 'ded-epyc-64';

    private string $defaultConnection;

    private Datacenter $datacenter;

    private DedicatedServer $server;

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

        $this->datacenter = Datacenter::factory()->create();

        // Exactly one machine of the profile, so that exactly one of the two
        // workers can be right.
        $this->server = DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile(self::PROFILE)
            ->create();
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
    public function two_workers_on_separate_connections_cannot_be_handed_the_same_machine(): void
    {
        DB::connection($this->defaultConnection)->beginTransaction();

        // Worker A takes the machine. The row lock is held and the write is
        // invisible to everyone else until it commits.
        $reservedByA = $this->reserveOnDefaultConnection();

        $this->assertSame($this->server->id, $reservedByA->id);

        try {
            $this->asWorkerB(fn (): DedicatedServer => $this->reserve());

            $this->fail('The second worker read and wrote the machine while the first was holding it.');
        } catch (QueryException $e) {
            /*
             * B waited for A's lock rather than proceeding, which is the whole
             * mechanism: without SELECT ... FOR UPDATE it would have read the
             * pre-reservation row, seen an available machine, and written its
             * own claim over A's.
             */
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        }

        // Only now does A finish. B did not get to write anything while it ran.
        DB::connection($this->defaultConnection)->commit();

        try {
            $this->asWorkerB(fn (): DedicatedServer => $this->reserve());

            $this->fail('One physical machine was promised to two customers.');
        } catch (NoMatchingHardwareException $e) {
            // B's retry reads the committed row and finds the machine gone.
            // The order goes to an operator; nobody is refunded and nobody is
            // handed somebody else's server.
            $this->assertSame(self::PROFILE, $e->context()['hardware_profile']);
            $this->assertSame('manual_review', $e->context()['disposition']);
        }

        $fresh = $this->freshServer();

        $this->assertSame(DedicatedServerStatus::Reserved, $fresh->status);
        $this->assertSame(1, DedicatedServer::on($this->defaultConnection)
            ->where('status', DedicatedServerStatus::Reserved->value)
            ->count());
    }

    #[Test]
    public function the_worker_that_commits_first_wins_and_the_second_is_refused(): void
    {
        // No open transaction this time: A simply gets there first, which is
        // the ordinary case.
        $this->reserveOnDefaultConnection();

        $this->expectException(NoMatchingHardwareException::class);

        // B is running against a machine that looked free when its job was
        // queued — precisely what a worker that sat behind a few seconds of
        // queue holds.
        $this->asWorkerB(fn (): DedicatedServer => $this->reserve());
    }

    #[Test]
    public function a_second_machine_of_the_profile_lets_both_workers_succeed_with_different_hardware(): void
    {
        // The lock must not serialise the whole inventory: with stock to go
        // round, two orders are two machines.
        DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile(self::PROFILE)
            ->create();

        $a = $this->reserveOnDefaultConnection();
        $b = $this->asWorkerB(fn (): DedicatedServer => $this->reserve());

        $this->assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function a_contended_machine_does_not_hide_the_free_ones_beside_it(): void
    {
        /*
         * The failure this guards against is quiet and expensive.
         *
         * Under READ COMMITTED, a blocked `SELECT ... ORDER BY ... LIMIT 1 FOR
         * UPDATE` does not simply wait and then move on. When the holder
         * commits, PostgreSQL re-evaluates the blocked statement's qualifiers
         * against the NEW row version — which no longer matches `allocatable()`
         * — and the statement returns nothing at all. Not "the next free
         * machine": nothing.
         *
         * So a rack with nine idle servers reported "no hardware available"
         * because a tenth was contended, and the order went to manual review
         * while the stock sat there. Reserving with SKIP LOCKED first is what
         * makes the second worker step over the locked row instead of queueing
         * behind it and then being told the shelf is empty.
         */
        $spare = DedicatedServer::factory()
            ->inDatacenter($this->datacenter)
            ->profile(self::PROFILE)
            ->create();

        DB::connection($this->defaultConnection)->beginTransaction();

        $reservedByA = $this->reserveOnDefaultConnection();
        $this->assertSame($this->server->id, $reservedByA->id, 'A should take the oldest machine.');

        // B must find the spare rather than blocking on A and then being told
        // there is nothing left.
        $reservedByB = $this->asWorkerB(fn (): DedicatedServer => $this->reserve());

        $this->assertSame($spare->id, $reservedByB->id);
        $this->assertSame(DedicatedServerStatus::Reserved, $reservedByB->status);

        DB::connection($this->defaultConnection)->rollBack();
    }

    #[Test]
    public function the_last_machine_is_still_waited_for_rather_than_skipped(): void
    {
        /*
         * SKIP LOCKED alone would be wrong here. With one machine and two
         * buyers, stepping over the locked row means reporting "none available"
         * while the first worker might still roll back — turning a transient
         * lock into a lost sale and a manual review.
         *
         * The second pass is a plain blocking lock, taken only when the first
         * found nothing, so the second worker waits for the committed truth.
         */
        DB::connection($this->defaultConnection)->beginTransaction();

        $this->reserveOnDefaultConnection();

        try {
            $this->asWorkerB(fn (): DedicatedServer => $this->reserve());

            $this->fail('B should have waited for A rather than reporting no stock.');
        } catch (QueryException $e) {
            // The lock timeout firing proves B queued rather than skipping —
            // exactly the behaviour the single-machine case needs.
            $this->assertStringContainsString('lock timeout', strtolower($e->getMessage()));
        } catch (NoMatchingHardwareException) {
            $this->fail('B skipped the contended machine instead of waiting for it.');
        } finally {
            DB::connection($this->defaultConnection)->rollBack();
        }
    }

    private function reserve(): DedicatedServer
    {
        return app(ReserveDedicatedServer::class)->execute(
            hardwareProfile: self::PROFILE,
            datacenterId: (string) $this->datacenter->getKey(),
        );
    }

    private function reserveOnDefaultConnection(): DedicatedServer
    {
        return $this->reserve();
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

    private function freshServer(): DedicatedServer
    {
        return DedicatedServer::on($this->defaultConnection)->findOrFail($this->server->getKey());
    }

    /**
     * Rows are committed for real here, so they are removed for real too.
     * Ordered by dependency: children before the machines, machines before the
     * facilities they sit in.
     */
    private function wipe(): void
    {
        PxeBootAuthorisation::on($this->defaultConnection)->delete();
        ServerComponent::on($this->defaultConnection)->delete();
        BmcEndpoint::on($this->defaultConnection)->delete();
        DedicatedServer::on($this->defaultConnection)->delete();
        Datacenter::on($this->defaultConnection)->delete();
        Region::on($this->defaultConnection)->delete();
    }
}
