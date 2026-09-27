<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Application\Handlers\DestroyVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\Support\RedisIndexForThisRun;
use Tests\TestCase;

/**
 * A destroy and a resize's settle, in two workers, leave nothing committed
 * for the machine the destroy removed (D7-1, round seven).
 *
 * The in-process interleaves (ADestroyThatOverlapsAResizeLeavesNothingCommittedTest)
 * run the destroy on the resize's own connection, so they cannot show that a
 * lock holds. This one does, deterministically: the destroy runs here and is
 * held just before it deletes the machine's row - its capacity already given
 * back - while the resize's settle runs in another process
 * ({@see machine_commitment_racer.php}) until it is seen waiting on a lock in
 * `pg_stat_activity` (not a sleep), or has finished. Then the destroy deletes
 * the row and commits.
 *
 * The destroy gives the capacity back and deletes the row under a lock on
 * the row taken first, so the settle waits, then finds the row gone and
 * writes nothing. A destroy that released outside that lock committed the
 * release at once; the settle found the row still there and nothing live
 * under the service, and committed the machine again under `machine:<id>`
 * for a machine that was deleted a moment later.
 *
 * Every table is emptied afterwards (LeavesNothingCommitted).
 */
final class ADestroyAndAResizeInTwoWorkersLeaveNothingCommittedTest extends TestCase
{
    use LeavesNothingCommitted;

    #[Test]
    public function a_settle_that_meets_a_destroy_waits_for_it_and_commits_nothing(): void
    {
        // Held just before the destroy deletes the row, its capacity given back.
        $this->raceASettleAgainstADestroy(static fn (string $query, bool $before): bool => $before
            && preg_match('/^delete from "virtual_machines"/', $query) === 1);
    }

    #[Test]
    public function a_settle_that_meets_a_destroy_just_after_its_release_waits_and_commits_nothing(): void
    {
        /*
         * Held just after the destroy has given the capacity back, before
         * anything else. A destroy that gives it back before taking the
         * machine's lock (the order it had before round seven) has committed
         * that release: the settle finds the row still there and nothing
         * live, and commits the machine again - the verifier's
         * destroy_after_release_then_resize race. Under the lock the settle
         * waits, and finds the row gone.
         */
        $this->raceASettleAgainstADestroy(static fn (string $query, bool $before): bool => ! $before
            && preg_match('/^update "node_capacity_reservations"/', $query) === 1);
    }

    /**
     * Runs a destroy here and, the first time $holdAt says so, a resize's
     * settle in another process until it is seen waiting on a lock or has
     * finished; then lets the destroy go on.
     *
     * @param  callable(string, bool): bool  $holdAt  the statement, and whether it is about to run (true) or has run
     */
    private function raceASettleAgainstADestroy(callable $holdAt): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'These rows must be committed for another process to see them.');

        $cluster = ComputeCluster::factory()->create(['driver' => 'fake']);
        $node = ComputeNode::factory()->withCapacity(32, 131072, 2048)->create([
            'cluster_id' => $cluster->id,
            'status' => NodeStatus::Active,
        ]);
        $pool = ComputeStorage::factory()->onNode($node)->create(['provider_name' => 'local-nvme', 'total_gib' => 2048, 'available_gib' => 2048]);
        $customer = Customer::factory()->create();
        $service = Service::factory()->active()->create(['customer_id' => $customer->id, 'kind' => 'vps']);

        // A machine the platform built, whose hypervisor side is already gone:
        // the destroy's work here is the release and the row.
        $machine = VirtualMachine::factory()->onNode($node)->forService($service)->resources(2, 4096, 40)->create(['storage_name' => 'local-nvme']);
        $machine->forceFill(['provider_id' => null])->save();
        app(ReserveNodeCapacity::class)->execute($node, new VmResources(2, 4096, 40), customerId: $customer->id, storageId: $pool->id, reservationKey: 'build:'.$service->id, serviceId: $service->id);

        $destroy = ProvisioningJob::factory()->create([
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'kind' => ProvisioningJobKind::DestroyVps,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Running,
            'payload' => ['virtual_machine_id' => (string) $machine->id],
        ]);

        $settle = null;
        $blocked = null;
        $hold = function (string $query, bool $before) use (&$settle, &$blocked, $machine, $holdAt): void {
            if ($settle !== null || ! $holdAt($query, $before)) {
                return;
            }

            $settle = $this->settleInAnotherProcess((string) $machine->id);
            $blocked = $this->untilBlockedOrFinished($settle);
        };
        DB::connection()->beforeExecuting(static function (string $query) use ($hold): void {
            $hold($query, true);
        });
        DB::listen(static function ($query) use ($hold): void {
            $hold($query->sql, false);
        });

        $this->assertTrue(app(DestroyVpsHandler::class)->execute($destroy)->successful);

        $this->assertInstanceOf(Process::class, $settle, 'The destroy never reached the statement it is held at.');
        $settle->wait();
        $line = trim($settle->getOutput());
        $this->assertNotSame('', $line, 'The settle produced no verdict: '.$settle->getErrorOutput());

        /** @var array{read: bool, restated: ?bool, sqlstate: ?string, error: ?string} $outcome */
        $outcome = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull($outcome['error'], 'The settle was stopped: '.$line);
        $this->assertTrue($outcome['read'], $line);
        $this->assertSame(0, VirtualMachine::query()->count());
        $this->assertSame(
            [],
            NodeCapacityReservation::query()->whereNull('released_at')->pluck('reservation_key')->all(),
            'The node is charged for a machine that is gone: '.$line,
        );
        $node->refresh();
        $this->assertSame([0, 0, 0, 0], [(int) $node->vm_count, (int) $node->allocated_cpu_cores, (int) $node->allocated_memory_mib, (int) $node->allocated_storage_gib]);
        $this->assertSame(0, (int) $pool->refresh()->committed_gib);
        $this->assertFalse($outcome['restated'], 'The settle restated a machine the destroy removed: '.$line);
        $this->assertTrue($blocked, 'The settle did not wait on the destroy: '.$line);
    }

    /**
     * Whether the other process was seen waiting on a lock (true) or had
     * already finished (false).
     */
    private function untilBlockedOrFinished(Process $process): bool
    {
        $deadline = microtime(true) + 45.0;

        while ($process->isRunning()) {
            // Inside a transaction the statistics views are a snapshot
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
                $this->fail('The settle neither waited nor finished: '.$process->getErrorOutput());
            }

            usleep(20_000);
        }

        return false;
    }

    private function settleInAnotherProcess(string $machineId): Process
    {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/machine_commitment_racer.php', $machineId],
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
}
