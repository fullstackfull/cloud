<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Application\Handlers\ResizeVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * A resize moves the machine's commitment on its node and pool, not only its
 * row (D2, round six).
 *
 * ResizeVpsHandler wrote the new shape to virtual_machines and nothing else:
 * the node's allocated_* figures, its pool's committed_gib and the capacity
 * reservation all went on describing the machine as it was bought. The
 * re-audit grew a 2 vCPU / 4096 MiB / 40 GiB machine to 16 / 65536 / 400 on a
 * 131072 MiB node, which still read 2 / 4096 / 40, then placed an 80000 MiB
 * machine beside it: 145536 MiB committed on a node that has 131072. A plan
 * upgrade is how a customer reaches it.
 *
 * So a growth is committed on the machine's node and pool before the
 * hypervisor is asked, and refused with a coded capacity failure when it does
 * not fit (nothing is grown, nothing is committed); a shrink gives the
 * difference back once the hypervisor has confirmed it; and the reservation
 * row carries the machine's shape, so a destroy gives back what is held.
 */
final class AResizeMovesTheNodeCommitmentTest extends TestCase
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
    public function a_growth_is_committed_on_the_node_and_its_pool(): void
    {
        $machine = $this->aBuiltMachine();

        $resize = $this->resize($machine, vcpu: 16, memoryMib: 65536, diskGib: 400);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->status, (string) $resize->last_error);
        $this->assertNodeHolds(vms: 1, vcpu: 16, memoryMib: 65536, diskGib: 400);
        $this->assertSame(400, $this->poolCommitted());

        $reservation = NodeCapacityReservation::query()->whereNull('released_at')->sole();
        $this->assertSame([16, 65536, 400], [$reservation->vcpu, $reservation->memory_mib, $reservation->disk_gib]);
        $this->assertSame((string) $this->node->id, $reservation->node_id);
    }

    #[Test]
    public function the_node_a_grown_machine_fills_is_not_sold_again(): void
    {
        $machine = $this->aBuiltMachine();
        $this->resize($machine, vcpu: 16, memoryMib: 65536, diskGib: 400);

        // The re-audit's second machine: 80000 MiB beside the grown 65536,
        // on a node with 131072.
        $second = $this->createJob(['memory_mib' => 80000, 'vcpu' => 8, 'disk_gib' => 100, 'hostname' => 'db-02']);
        $this->runWorker($second);

        $this->assertNotSame(ProvisioningJobStatus::Succeeded, $second->refresh()->status, 'A second machine was placed on the node a resize had filled.');
        $this->assertSame(FailureClass::Capacity, $second->failure_class);
        $this->assertLessThanOrEqual(
            (int) $this->node->refresh()->memory_mib,
            (int) VirtualMachine::query()->where('node_id', $this->node->id)->sum('memory_mib'),
            'The machines on the node are sold more memory than it has.',
        );
        $this->assertNodeHolds(vms: 1, vcpu: 16, memoryMib: 65536, diskGib: 400);
    }

    #[Test]
    public function a_growth_the_node_cannot_hold_is_refused_before_the_hypervisor_is_asked(): void
    {
        $machine = $this->aBuiltMachine();

        $resize = $this->resize($machine, vcpu: 2, memoryMib: 125000, diskGib: 40);

        $this->assertNotSame(ProvisioningJobStatus::Succeeded, $resize->status);
        $this->assertSame(FailureClass::Capacity, $resize->failure_class);
        $this->assertSame('compute.node_capacity_exceeded', $resize->result['error']['code'] ?? null);

        // Nothing grown, nothing committed.
        $this->assertSame(4096, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->memoryMib);
        $this->assertSame(4096, $machine->refresh()->memory_mib);
        $this->assertNodeHolds(vms: 1, vcpu: 2, memoryMib: 4096, diskGib: 40);
        $this->assertSame(4096, NodeCapacityReservation::query()->whereNull('released_at')->sole()->memory_mib);
    }

    #[Test]
    public function a_shrink_gives_the_difference_back(): void
    {
        $machine = $this->aBuiltMachine();

        $resize = $this->resize($machine, vcpu: 1, memoryMib: 2048, diskGib: 40);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->status, (string) $resize->last_error);
        $this->assertNodeHolds(vms: 1, vcpu: 1, memoryMib: 2048, diskGib: 40);
        $this->assertSame(2048, NodeCapacityReservation::query()->whereNull('released_at')->sole()->memory_mib);
    }

    #[Test]
    public function a_growth_the_hypervisor_refused_is_given_back(): void
    {
        $machine = $this->aBuiltMachine();
        $this->hypervisor->failResizesWith = ComputeProviderException::requestFailed('fake', 'resize_vm', ['provider_message' => 'refused']);

        $resize = $this->resize($machine, vcpu: 16, memoryMib: 65536, diskGib: 400);

        $this->assertSame(FailureClass::Transient, $resize->failure_class);
        $this->assertNodeHolds(vms: 1, vcpu: 2, memoryMib: 4096, diskGib: 40);
        $this->assertSame(40, $this->poolCommitted());
    }

    #[Test]
    public function a_growth_whose_outcome_is_unknown_stays_committed(): void
    {
        $machine = $this->aBuiltMachine();
        $this->hypervisor->failResizesWith = ComputeProviderException::requestFailed('fake', 'resize_vm', [], indeterminate: true);

        $resize = $this->resize($machine, vcpu: 16, memoryMib: 65536, diskGib: 400);

        // The machine may have grown: it is held as grown until a person looks.
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $resize->status);
        $this->assertNodeHolds(vms: 1, vcpu: 16, memoryMib: 65536, diskGib: 400);
    }

    #[Test]
    public function a_destroy_after_a_growth_gives_back_what_the_machine_held(): void
    {
        $machine = $this->aBuiltMachine();
        $this->resize($machine, vcpu: 16, memoryMib: 65536, diskGib: 400);

        $destroy = ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $this->customer->id,
            'kind' => ProvisioningJobKind::DestroyVps,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Queued,
            'payload' => ['virtual_machine_id' => (string) $machine->id],
        ]);
        $this->runWorker($destroy);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $destroy->refresh()->status, (string) $destroy->last_error);
        $this->assertNodeHolds(vms: 0, vcpu: 0, memoryMib: 0, diskGib: 0);
        $this->assertSame(0, $this->poolCommitted());
    }

    #[Test]
    public function a_disk_growth_the_pool_cannot_hold_is_refused_before_the_hypervisor_is_asked(): void
    {
        $machine = $this->aBuiltMachine();
        // 60 GiB reported free in the pool, 40 of it committed to this machine.
        ComputeStorage::query()->where('node_id', $this->node->id)->update(['available_gib' => 60]);

        $resize = $this->resize($machine, vcpu: 2, memoryMib: 4096, diskGib: 200);

        $this->assertSame(FailureClass::Capacity, $resize->failure_class);
        $this->assertSame(40, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->diskGib);
        $this->assertSame(40, $this->poolCommitted());
    }

    #[Test]
    public function a_shrink_is_never_refused_on_a_node_already_past_its_ceiling(): void
    {
        $machine = $this->aBuiltMachine();
        // The node now reports less memory than is committed on it (a sync
        // after a DIMM was lost): giving some back must still be possible.
        $this->node->forceFill(['memory_mib' => 4096])->save();

        $resize = $this->resize($machine, vcpu: 1, memoryMib: 2048, diskGib: 40);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->status, (string) $resize->last_error);
        $this->assertNodeHolds(vms: 1, vcpu: 1, memoryMib: 2048, diskGib: 40);
    }

    #[Test]
    public function a_growth_is_refused_only_for_what_grows(): void
    {
        $machine = $this->aBuiltMachine();
        // Past its memory ceiling, with disk to spare: a disk-only growth
        // asks nothing of the memory.
        $this->node->forceFill(['memory_mib' => 4096])->save();

        $resize = $this->resize($machine, vcpu: 2, memoryMib: 4096, diskGib: 80);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->status, (string) $resize->last_error);
        $this->assertNodeHolds(vms: 1, vcpu: 2, memoryMib: 4096, diskGib: 80);
    }

    #[Test]
    public function a_commitment_held_raised_is_not_lowered_by_the_next_resize_before_it_is_known(): void
    {
        $machine = $this->aBuiltMachine();

        // A growth whose outcome is unknown: held raised, machine as recorded.
        $this->hypervisor->failResizesWith = ComputeProviderException::requestFailed('fake', 'resize_vm', [], indeterminate: true);
        $this->resize($machine, vcpu: 16, memoryMib: 65536, diskGib: 400);
        $this->assertNodeHolds(vms: 1, vcpu: 16, memoryMib: 65536, diskGib: 400);

        // A smaller resize whose outcome is unknown as well: the machine may
        // still be the larger shape, so the larger commitment stands.
        $this->resize($machine->refresh(), vcpu: 2, memoryMib: 8192, diskGib: 40);

        $this->assertNodeHolds(vms: 1, vcpu: 16, memoryMib: 65536, diskGib: 400);
    }

    #[Test]
    public function two_resizes_that_overlap_leave_the_commitment_at_the_machines_shape(): void
    {
        /*
         * Nothing serialises two resize jobs of one machine. The first
         * settled its commitment from the machine as it held it in memory; a
         * second that ran whole between the first's write of the machine row
         * and its settle left the machine at 16384 MiB and the commitment at
         * the first's 8192 - under-committed, and sold again.
         */
        $machine = $this->aBuiltMachine();
        $first = $this->resizeJob($machine, vcpu: 2, memoryMib: 8192, diskGib: 40);
        $second = $this->resizeJob($machine, vcpu: 2, memoryMib: 16384, diskGib: 40);

        $fired = false;
        DB::listen(function ($query) use (&$fired, $second): void {
            if ($fired || ! str_starts_with(strtolower($query->sql), 'update "virtual_machines"')) {
                return;
            }

            $fired = true;
            $this->assertTrue(app(ResizeVpsHandler::class)->execute($second->fresh())->successful);
        });

        $this->assertTrue(app(ResizeVpsHandler::class)->execute($first->fresh())->successful);
        $this->assertTrue($fired);

        $this->assertSame(16384, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->memoryMib);
        $this->assertSame(16384, $machine->refresh()->memory_mib);
        $this->assertNodeHolds(vms: 1, vcpu: 2, memoryMib: 16384, diskGib: 40);
        $this->assertSame(16384, NodeCapacityReservation::query()->whereNull('released_at')->sole()->memory_mib);
    }

    #[Test]
    public function a_resize_whose_machine_cannot_be_read_back_is_settled_to_what_is_known(): void
    {
        /*
         * vps.resize_unverified is a timeout - the machine may be either
         * shape, and the job stops in review, which holds the service's plan
         * changes (ServiceBusy) until a person settles it (A8-1, the
         * re-audit after round seven: it was permanent, the job failed, and a
         * downgrade was credited from the row it had not written). It used
         * to leave the commitment at the ceiling the growth raised it to - here the 16 /
         * 65536 / 400 an earlier unknown outcome left held - with nothing to
         * settle it after. What is known: the machine was 2 / 4096 / 40 just
         * before this resize (looked at), and the hypervisor accepted a change
         * to 2 / 8192 / 40. The machine is one of the two.
         */
        $machine = $this->aBuiltMachine();
        $this->hypervisor->failResizesWith = ComputeProviderException::requestFailed('fake', 'resize_vm', [], indeterminate: true);
        $this->resize($machine, vcpu: 16, memoryMib: 65536, diskGib: 400);
        $this->assertNodeHolds(vms: 1, vcpu: 16, memoryMib: 65536, diskGib: 400);
        $this->hypervisor->failResizesWith = null;

        $this->hypervisor->afterAResize = function (): void {
            $this->hypervisor->reportNoMachines = true;
        };
        $resize = $this->resize($machine->refresh(), vcpu: 2, memoryMib: 8192, diskGib: 40);

        $this->assertSame(FailureClass::Timeout, $resize->failure_class);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $resize->status);
        $this->assertSame('vps.resize_unverified', $resize->result['error']['code'] ?? null);
        $this->assertNodeHolds(vms: 1, vcpu: 2, memoryMib: 8192, diskGib: 40);
        $this->assertSame(40, $this->poolCommitted());
        $this->assertSame([2, 8192, 40], $this->liveShape());
        // Nothing confirmed the shape, so the row is as it was.
        $this->assertSame(4096, $machine->refresh()->memory_mib);
    }

    #[Test]
    public function a_resize_whose_read_back_fails_is_settled_to_what_is_known(): void
    {
        $machine = $this->aBuiltMachine();
        $this->hypervisor->failResizesWith = ComputeProviderException::requestFailed('fake', 'resize_vm', [], indeterminate: true);
        $this->resize($machine, vcpu: 16, memoryMib: 65536, diskGib: 400);
        $this->hypervisor->failResizesWith = null;

        // A shrink of memory whose read-back fails: the machine may still be
        // the 4096 it was, so that is what stays committed - not 65536.
        $this->hypervisor->afterAResize = function (): void {
            $this->hypervisor->failReadsWith = ComputeProviderException::requestFailed('fake', 'get_vm', [], indeterminate: true);
        };
        $resize = $this->resize($machine->refresh(), vcpu: 1, memoryMib: 2048, diskGib: 80);
        $this->hypervisor->failReadsWith = null;

        $this->assertSame('vps.resize_unverified', $resize->result['error']['code'] ?? null);
        $this->assertNodeHolds(vms: 1, vcpu: 2, memoryMib: 4096, diskGib: 80);
        $this->assertSame([2, 4096, 80], $this->liveShape());
    }

    #[Test]
    public function a_figure_asked_for_and_not_read_back_is_not_taken_to_be_so(): void
    {
        /*
         * "Read back rather than assumed": a vCPU or memory figure the
         * read-back did not report used to be recorded as the target, and the
         * job succeeded on it. It confirms nothing now: the resize is
         * unverified, the row keeps what was last confirmed, and the
         * commitment holds the larger shape.
         */
        $machine = $this->aBuiltMachine();
        $this->hypervisor->afterAResize = function (): void {
            $this->hypervisor->reportNoFigures = true;
        };

        $resize = $this->resize($machine, vcpu: 4, memoryMib: 8192, diskGib: 40);
        $this->hypervisor->reportNoFigures = false;

        $this->assertSame('vps.resize_unverified', $resize->result['error']['code'] ?? null);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $resize->status);
        $this->assertSame([2, 4096, 40], [$machine->refresh()->vcpu, $machine->memory_mib, $machine->disk_gib]);
        $this->assertSame([4, 8192, 40], $this->liveShape());
    }

    /**
     * @return array{int, int, int}
     */
    private function liveShape(): array
    {
        $live = NodeCapacityReservation::query()->whereNull('released_at')->sole();

        return [(int) $live->vcpu, (int) $live->memory_mib, (int) $live->disk_gib];
    }

    private function aBuiltMachine(): VirtualMachine
    {
        $job = $this->createJob();
        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status, (string) $job->last_error);
        $this->assertNodeHolds(vms: 1, vcpu: 2, memoryMib: 4096, diskGib: 40);

        return VirtualMachine::query()->sole();
    }

    private function resize(VirtualMachine $machine, int $vcpu, int $memoryMib, int $diskGib): ProvisioningJob
    {
        $resize = $this->resizeJob($machine, $vcpu, $memoryMib, $diskGib);
        $this->runWorker($resize);

        return $resize->refresh();
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

    private function assertNodeHolds(int $vms, int $vcpu, int $memoryMib, int $diskGib): void
    {
        $node = DB::table('compute_nodes')->where('id', $this->node->id)->first();

        $this->assertSame(
            ['vm_count' => $vms, 'cpu' => $vcpu, 'memory' => $memoryMib, 'disk' => $diskGib],
            ['vm_count' => (int) $node->vm_count, 'cpu' => (int) $node->allocated_cpu_cores, 'memory' => (int) $node->allocated_memory_mib, 'disk' => (int) $node->allocated_storage_gib],
        );
    }

    private function poolCommitted(): int
    {
        return (int) ComputeStorage::query()->where('node_id', $this->node->id)->sole()->committed_gib;
    }
}
