<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Dedicated\Application\Actions\RetireDedicatedServer;
use Lynomia\Modules\Dedicated\Application\Actions\SyncHardwareInventory;
use Lynomia\Modules\Dedicated\Application\Jobs\SyncDedicatedServer;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedServer;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The sweep that makes the hardware sync actually run.
 *
 * SyncHardwareInventory documents itself as running "on a schedule across the
 * whole fleet" and nothing ran it. The platform's picture of a customer's
 * physical server — its firmware, its disks, a failing power supply — was
 * whatever had been typed in the day the chassis was racked.
 */
final class DedicatedInventorySweepTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_queues_one_job_per_machine_rather_than_one_for_the_fleet(): void
    {
        /*
         * One job each, because a BMC is the slowest and least reliable thing
         * the platform talks to. A single job for the fleet would let one
         * unreachable controller hold up every other machine's refresh — and
         * the machine that stopped answering is the one worth hearing about.
         */
        Queue::fake([SyncDedicatedServer::class]);

        DedicatedServer::factory()->count(3)->status(DedicatedServerStatus::Active)->create();

        $this->artisan('dedicated:sync-inventory')->assertExitCode(0);

        Queue::assertPushed(SyncDedicatedServer::class, 3);
    }

    #[Test]
    public function a_retired_chassis_is_left_alone_and_a_failed_one_is_not(): void
    {
        Queue::fake([SyncDedicatedServer::class]);

        DedicatedServer::factory()->status(DedicatedServerStatus::Retired)->create();

        // A machine marked failed is one somebody needs the current hardware
        // picture of. That is exactly when its old picture is most misleading,
        // so it stays in the sweep.
        DedicatedServer::factory()->status(DedicatedServerStatus::Failed)->create();

        $this->artisan('dedicated:sync-inventory')->assertExitCode(0);

        Queue::assertPushed(SyncDedicatedServer::class, 1);
    }

    #[Test]
    public function the_job_runs_on_the_infrastructure_queue(): void
    {
        // Not on provisioning. A fleet sweep must never sit in front of a
        // customer's build, waiting on controllers, while they watch a
        // spinner.
        $this->assertSame('infrastructure', SyncDedicatedServer::QUEUE);

        Queue::fake([SyncDedicatedServer::class]);

        $server = DedicatedServer::factory()->status(DedicatedServerStatus::Active)->create();

        $this->artisan('dedicated:sync-inventory', ['--server' => (string) $server->getKey()])
            ->assertExitCode(0);

        Queue::assertPushed(
            SyncDedicatedServer::class,
            static fn (SyncDedicatedServer $job): bool => $job->queue === 'infrastructure',
        );
    }

    #[Test]
    public function a_machine_with_no_controller_endpoint_is_recorded_and_not_retried(): void
    {
        /*
         * A gap in the inventory rather than a failure: no number of attempts
         * will invent an address for a machine nobody recorded one for. The
         * job must not fail, because a failed job here means one row in the
         * failed-jobs table per pass, for ever, burying the genuine failures.
         */
        $server = DedicatedServer::factory()->status(DedicatedServerStatus::Active)->create();

        $this->assertNull($server->preferredBmcEndpoint());

        // No exception escapes.
        (new SyncDedicatedServer((string) $server->getKey()))
            ->handle(app(SyncHardwareInventory::class));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_machine_that_disappeared_between_the_sweep_and_the_worker_is_not_an_error(): void
    {
        // Retired and removed while the job sat on the queue. Failing here
        // would page somebody about a machine deliberately taken away.
        (new SyncDedicatedServer('01jnosuchserverxxxxxxxxxxx'))
            ->handle(app(SyncHardwareInventory::class));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_machine_retired_after_the_sweep_is_found_by_its_worker_and_left_out_of_the_next_sweep(): void
    {
        /*
         * The race the missing-row branch above used to be blamed on, run
         * through the real operator act. Retiring keeps the row, so the job
         * queued before it finds the machine and runs its sync once more; it
         * is the next sweep that leaves the machine out.
         */
        Queue::fake([SyncDedicatedServer::class]);

        $server = DedicatedServer::factory()->status(DedicatedServerStatus::Maintenance)->create();

        $this->artisan('dedicated:sync-inventory')->assertExitCode(0);

        /** @var SyncDedicatedServer $queued */
        $queued = Queue::pushed(SyncDedicatedServer::class)->sole();

        app(RetireDedicatedServer::class)->execute($server);

        $this->assertSame(DedicatedServerStatus::Retired, DedicatedServer::query()->findOrFail($server->getKey())->status);

        // Found, not missing: the worker reaches the sync, which stops at the
        // absent controller endpoint. The missing-row branch logs nothing.
        Log::spy();

        $queued->handle(app(SyncHardwareInventory::class));

        Log::shouldHaveReceived('info')
            ->with('A dedicated server has no BMC endpoint to sync from.', Mockery::on(
                static fn (array $context): bool => $context['server_id'] === (string) $server->getKey(),
            ))
            ->once();

        $this->artisan('dedicated:sync-inventory')->assertExitCode(0);

        Queue::assertPushed(SyncDedicatedServer::class, 1);
    }
}
