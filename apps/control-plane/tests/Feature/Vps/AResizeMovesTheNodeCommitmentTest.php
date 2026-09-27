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
        $resize = ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $this->customer->id,
            'kind' => ProvisioningJobKind::Resize,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Queued,
            'payload' => ['virtual_machine_id' => (string) $machine->id, 'vcpu' => $vcpu, 'memory_mib' => $memoryMib, 'disk_gib' => $diskGib],
        ]);
        $this->runWorker($resize);

        return $resize->refresh();
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
