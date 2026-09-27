<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Application\Handlers\DestroyVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * A destroy that overlaps a resize leaves nothing committed for the machine
 * it removed (D7-1, round seven).
 *
 * Nothing serialises provisioning jobs per service. A destroy that ran whole
 * between a resize's write of the machine row and its settle released the
 * commitment and deleted the row; the settle then found no live reservation
 * under the service and committed the machine again under a key of its own
 * (`machine:<id>`): the node held 1 machine and 4 / 8192 / 80 with no machine
 * on it, and nothing would ever release it.
 *
 * The destroy's release and delete, and every restatement of a machine's
 * commitment, now run under a lock on the machine's row taken first; a
 * restatement that finds the row gone writes nothing. Each interleave below
 * runs the destroy whole at a point a real destroy can run, in process, on
 * the resize's own connection.
 */
final class ADestroyThatOverlapsAResizeLeavesNothingCommittedTest extends TestCase
{
    use DrivesVpsCreatesThroughTheOperatorPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->setUpTheCluster();
        $this->hypervisor->loseTheAnswerToCreates = false;
    }

    #[Test]
    public function a_destroy_between_the_resizes_write_of_the_row_and_its_settle_leaves_nothing_committed(): void
    {
        $machine = $this->aBuiltMachine();
        $resize = $this->resizeJob($machine, vcpu: 4, memoryMib: 8192, diskGib: 80);

        $fired = $this->destroyWhenTheResizeRuns($machine, '/^update "virtual_machines"/');
        $this->runWorker($resize);
        $this->assertTrue($fired(), 'The destroy never ran inside the resize.');

        $this->assertSame(0, VirtualMachine::query()->count());
        $this->assertSame([], $this->hypervisor->everyMachine());
        $this->assertNothingCommitted();
        $this->assertSame(ProvisioningJobStatus::Failed, $resize->refresh()->status);
        $this->assertSame(FailureClass::Permanent, $resize->failure_class);
        $this->assertSame('vps.unknown_machine', $resize->result['error']['code'] ?? null);
    }

    #[Test]
    public function a_destroy_after_the_resize_read_the_machine_leaves_nothing_committed(): void
    {
        $machine = $this->aBuiltMachine();
        $resize = $this->resizeJob($machine, vcpu: 4, memoryMib: 8192, diskGib: 80);

        // Right after the resize has read the machine row, before it looks at
        // the hypervisor or commits the growth.
        $fired = $this->destroyWhenTheResizeRuns($machine, '/^select \* from "compute_clusters"/');
        $this->runWorker($resize);
        $this->assertTrue($fired(), 'The destroy never ran inside the resize.');

        $this->assertSame(0, VirtualMachine::query()->count());
        $this->assertNothingCommitted();
        $this->assertNotSame(ProvisioningJobStatus::Succeeded, $resize->refresh()->status);
    }

    #[Test]
    public function a_destroy_between_the_hypervisor_look_and_the_growths_commitment_leaves_nothing_committed(): void
    {
        $machine = $this->aBuiltMachine();
        $resize = $this->resizeJob($machine, vcpu: 4, memoryMib: 8192, diskGib: 80);

        // The resize has looked at the machine and found it; the destroy runs
        // whole just before the growth is committed: before the commitment
        // takes the machine's lock, or (without that lock) before it reads
        // the machine's reservation.
        $fired = $this->destroyWhenTheResizeRuns($machine, '/^select ("id" from "virtual_machines" .* for update$|\* from "node_capacity_reservations")/', before: true);
        $this->runWorker($resize);
        $this->assertTrue($fired(), 'The destroy never ran inside the resize.');

        $this->assertSame(0, VirtualMachine::query()->count());
        $this->assertNothingCommitted();
        $this->assertSame('vps.unknown_machine', $resize->refresh()->result['error']['code'] ?? null);
    }

    #[Test]
    public function a_destroy_while_the_hypervisor_resizes_ends_the_resize_unknown_machine_whatever_it_answers(): void
    {
        /*
         * The destroy runs whole while the hypervisor is resizing: the
         * resize is then refused (the settle after a refusal) or its
         * machine cannot be read back (the settle of an unverified
         * resize). Each settle finds the row gone and writes nothing; the
         * resize used to report the refusal, or vps.resize_unverified, as
         * if the machine were still there.
         */
        foreach (['refused' => ComputeProviderException::requestFailed('fake', 'resize_vm', ['provider_message' => 'refused']), 'unverified' => null] as $answer => $refusal) {
            $machine = $this->aBuiltMachine();
            $destroy = $this->destroyJob($machine);
            $this->hypervisor->afterAResize = function () use ($destroy, $refusal): void {
                $this->assertTrue(app(DestroyVpsHandler::class)->execute($destroy->fresh())->successful);

                if ($refusal !== null) {
                    throw $refusal;
                }
            };

            $resize = $this->resizeJob($machine, vcpu: 4, memoryMib: 8192, diskGib: 80);
            $this->runWorker($resize);

            $this->assertSame(0, VirtualMachine::query()->count(), $answer);
            $this->assertNothingCommitted();
            $this->assertSame(FailureClass::Permanent, $resize->refresh()->failure_class, $answer);
            $this->assertSame('vps.unknown_machine', $resize->result['error']['code'] ?? null, $answer);
        }
    }

    #[Test]
    public function a_destroy_locks_every_reservation_of_the_service_then_the_nodes_then_the_pools(): void
    {
        // Two live reservations of one service, on two nodes.
        $machine = $this->aBuiltMachine();
        $other = $this->addNode('pve-02');
        $pool = ComputeStorage::query()->where('node_id', $other->id)->sole();
        app(ReserveNodeCapacity::class)->execute($other, new VmResources(1, 1024, 10), customerId: $this->customer->id, storageId: $pool->id, reservationKey: 'second:'.$machine->service_id, serviceId: $machine->service_id);

        $locks = [];
        DB::listen(static function ($query) use (&$locks): void {
            if (preg_match('/^select \* from "(node_capacity_reservations|compute_nodes|compute_storages)" .*for update$/', $query->sql, $table) === 1) {
                $locks[] = $table[1];
            }
        });
        $this->runWorker($this->destroyJob($machine));

        $this->assertNothingCommitted();
        $this->assertSame(0, (int) $other->refresh()->vm_count);
        // The first locks, in order: the reservations, then both nodes, then both pools.
        $this->assertSame(
            ['node_capacity_reservations', 'compute_nodes', 'compute_nodes', 'compute_storages', 'compute_storages'],
            array_slice($locks, 0, 5),
            json_encode($locks),
        );
    }

    private function aBuiltMachine(): VirtualMachine
    {
        $job = $this->createJob();
        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status, (string) $job->last_error);

        return VirtualMachine::query()->sole();
    }

    /**
     * Runs a destroy of the machine whole the first time the resize makes a
     * statement matching $sql: after it ran, or, $before, just before it
     * runs. Returns whether it ran.
     *
     * @return callable(): bool
     */
    private function destroyWhenTheResizeRuns(VirtualMachine $machine, string $sql, bool $before = false): callable
    {
        $destroy = $this->destroyJob($machine);
        $fired = false;

        $run = function (string $statement) use (&$fired, $destroy, $sql): void {
            if ($fired || ! preg_match($sql, strtolower($statement))) {
                return;
            }

            $fired = true;
            $this->assertTrue(app(DestroyVpsHandler::class)->execute($destroy->fresh())->successful);
        };

        if ($before) {
            DB::connection()->beforeExecuting(static function (string $query) use ($run): void {
                $run($query);
            });
        } else {
            DB::listen(static function ($query) use ($run): void {
                $run($query->sql);
            });
        }

        return static function () use (&$fired): bool {
            return $fired;
        };
    }

    private function resizeJob(VirtualMachine $machine, int $vcpu, int $memoryMib, int $diskGib): ProvisioningJob
    {
        return ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $this->customer->id,
            'kind' => ProvisioningJobKind::Resize,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Queued,
            'payload' => ['virtual_machine_id' => (string) $machine->id, 'vcpu' => $vcpu, 'memory_mib' => $memoryMib, 'disk_gib' => $diskGib],
        ]);
    }

    private function destroyJob(VirtualMachine $machine): ProvisioningJob
    {
        return ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $this->customer->id,
            'kind' => ProvisioningJobKind::DestroyVps,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Queued,
            'payload' => ['virtual_machine_id' => (string) $machine->id],
        ]);
    }

    private function assertNothingCommitted(): void
    {
        $node = DB::table('compute_nodes')->where('id', $this->node->id)->first();

        $this->assertSame(
            [0, 0, 0, 0],
            [(int) $node->vm_count, (int) $node->allocated_cpu_cores, (int) $node->allocated_memory_mib, (int) $node->allocated_storage_gib],
            'The node is still charged for a machine that is gone: '.json_encode(NodeCapacityReservation::query()->whereNull('released_at')->get(['reservation_key', 'vcpu', 'memory_mib', 'disk_gib'])),
        );
        $this->assertSame(0, NodeCapacityReservation::query()->whereNull('released_at')->count());
        $this->assertSame(0, (int) ComputeStorage::query()->where('node_id', $this->node->id)->sole()->committed_gib);
    }
}
