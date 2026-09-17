<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\Datacenter;
use Lynomia\Modules\Compute\Infrastructure\Models\Region;
use Lynomia\Modules\Dedicated\Application\Actions\ChangeDedicatedServerPower;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedPowerAction;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;
use Lynomia\Modules\Dedicated\Domain\Exceptions\DedicatedOperationRefusedException;
use Lynomia\Modules\Dedicated\Infrastructure\DedicatedProviderFactory;
use Lynomia\Modules\Dedicated\Infrastructure\Models\BmcEndpoint;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedPowerOperation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Lynomia\Modules\Dedicated\Infrastructure\Models\PxeBootAuthorisation;
use Lynomia\Modules\Dedicated\Infrastructure\Models\ServerComponent;
use Lynomia\Modules\Dedicated\Infrastructure\Providers\FakeDedicatedProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CountingDedicatedProvider;
use Tests\TestCase;

/**
 * Two requests carrying one idempotency key, arriving at the same moment, on
 * two real database connections.
 *
 * A double-submitted button and a client retry are the same thing from the
 * server's side, and neither is polite enough to arrive after the first
 * request has finished. The guard therefore cannot be a read followed by a
 * write: both readers would find nothing, both would call the controller, and
 * the second reset would interrupt the boot the first one started.
 *
 * What makes it safe is the unique index on `idempotency_key`. This test is
 * here to prove that claim rather than assume it — that the second insert
 * genuinely fails at the database, on a connection that cannot see the first
 * one's uncommitted row, and that the loser does not reach the chassis.
 *
 * It deliberately does not use RefreshDatabase, for the same reason
 * {@see ConcurrentDedicatedReservationTest} does not: that trait wraps the test
 * in one transaction on one connection, where the second connection cannot see
 * the fixtures and two queries are serialised by definition and can never
 * race. Rows are committed for real and removed in the teardown.
 */
final class ConcurrentDedicatedPowerTest extends TestCase
{
    private const string SECOND_CONNECTION = 'power_worker_b';

    private string $defaultConnection;

    private DedicatedServer $server;

    private BmcEndpoint $endpoint;

    private CountingDedicatedProvider $counting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultConnection = (string) config('database.default');
        $this->wipe();

        config([
            'database.connections.'.self::SECOND_CONNECTION => config(
                'database.connections.'.$this->defaultConnection,
            ),
        ]);

        // A blocked insert against a unique index waits for the holder to
        // commit or roll back. A short timeout turns a hang into an assertable
        // failure if the mechanism ever changes.
        DB::connection(self::SECOND_CONNECTION)->statement("SET lock_timeout = '3s'");

        $datacenter = Datacenter::factory()->create();

        $this->server = DedicatedServer::factory()
            ->inDatacenter($datacenter)
            ->create([
                'status' => DedicatedServerStatus::Active,
                'power_state' => PowerState::On,
            ]);

        $this->endpoint = BmcEndpoint::factory()->forServer($this->server)->create();

        // One provider instance for both callers, so the count is the number
        // of times the chassis was actually asked to do something.
        $this->app->singleton(DedicatedProviderFactory::class);

        $this->counting = new CountingDedicatedProvider(new FakeDedicatedProvider);

        app(DedicatedProviderFactory::class)->swap($this->endpoint, $this->counting);
    }

    protected function tearDown(): void
    {
        while (DB::connection($this->defaultConnection)->transactionLevel() > 0) {
            DB::connection($this->defaultConnection)->rollBack();
        }

        DB::purge(self::SECOND_CONNECTION);
        $this->wipe();

        parent::tearDown();
    }

    #[Test]
    public function the_second_arrival_of_one_key_never_reaches_the_chassis(): void
    {
        $power = app(ChangeDedicatedServerPower::class);

        $power->execute($this->server, DedicatedPowerAction::Cycle, clientKey: 'double-submitted');

        $this->assertSame(1, $this->counting->calls);

        // The same key again, on a different connection and therefore a
        // different transaction and a different set of locks: the shape of a
        // retry landing on a second web worker.
        $replayed = $this->asWorkerB(fn () => $power->execute(
            $this->freshServer(),
            DedicatedPowerAction::Cycle,
            clientKey: 'double-submitted',
        ));

        $this->assertSame(1, $this->counting->calls, 'the retry reached the controller a second time');
        // `power_on`, because `cycle` reads the chassis first and the
        // controlled controller reports a machine it has not been told about
        // as off. Which verb it is does not matter here; that there is exactly
        // one of them does.
        $this->assertSame(['power_on'], $this->counting->operations);
        $this->assertTrue($replayed->accepted);

        $this->assertSame(1, DedicatedPowerOperation::on($this->defaultConnection)->count());
    }

    #[Test]
    public function a_request_that_is_still_in_flight_is_refused_rather_than_sent_again(): void
    {
        /*
         * The interleaving the unique index exists for.
         *
         * The claim is committed and the outcome is still `claimed`, which is
         * exactly the state a first request is in while it is talking to the
         * controller: the row is written — deliberately before the call, so
         * that a process which dies mid-call leaves evidence — and no outcome
         * is recorded yet. This is committed rather than held in an open
         * transaction because that is what the action does: the claim commits,
         * and only then is the BMC called.
         *
         * Worker B arrives with the same key while that is true. It must not
         * reach the chassis and must not report success. It is told the first
         * request is still being carried out, which is the only honest answer
         * — the reset A sent may be in progress, and a second one would
         * interrupt it.
         */
        DedicatedPowerOperation::on($this->defaultConnection)->create([
            'dedicated_server_id' => $this->server->getKey(),
            'customer_id' => $this->server->customer_id,
            'action' => DedicatedPowerAction::Cycle,
            'idempotency_key' => 'dedicated:'.$this->server->getKey().':power:cycle:'
                .hash('sha256', 'in-flight-key'),
            'outcome' => 'claimed',
            'requested_at' => now(),
        ]);

        try {
            $this->asWorkerB(fn () => app(ChangeDedicatedServerPower::class)->execute(
                $this->freshServer(),
                DedicatedPowerAction::Cycle,
                clientKey: 'in-flight-key',
            ));

            $this->fail('A second request with the same key reached the controller while the first was in flight.');
        } catch (DedicatedOperationRefusedException $e) {
            $this->assertSame('dedicated.operation_refused', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        $this->assertSame(0, $this->counting->calls, 'the chassis was asked to act on a duplicate request');

        // And no second row: the loser of the race does not leave a claim
        // behind for a third request to trip over.
        $this->assertSame(1, DedicatedPowerOperation::on($this->defaultConnection)->count());
    }

    /**
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
     */
    private function wipe(): void
    {
        DedicatedPowerOperation::on($this->defaultConnection)->delete();
        PxeBootAuthorisation::on($this->defaultConnection)->delete();
        ServerComponent::on($this->defaultConnection)->delete();
        BmcEndpoint::on($this->defaultConnection)->delete();
        DedicatedServer::on($this->defaultConnection)->delete();
        Datacenter::on($this->defaultConnection)->delete();
        Region::on($this->defaultConnection)->delete();
    }
}
