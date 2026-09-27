<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
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
 * An operator's retry of a resize that landed does not grow the disk again
 * (D7-2, round seven; F-15's "look before you act", for a resize).
 *
 * A 40 -> 400 GiB growth reached the hypervisor and its answer was lost: the
 * job stopped for review (a timeout is never retried automatically). The
 * operator's retry was accepted - the retry refuses only what an attempt
 * wrote down - and the handler worked the growth out from the machine row,
 * still 40, and asked for +360 again: the customer had a 760 GiB disk and
 * paid for 400. The handler now reads the machine from the hypervisor before
 * it grows anything, and a disk already at the target is not grown.
 */
final class ARetriedResizeLooksAtTheMachineBeforeGrowingItTest extends TestCase
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
    public function an_operator_retry_of_a_resize_that_landed_does_not_grow_the_disk_again(): void
    {
        $machine = $this->aBuiltMachine();

        // The resize lands at the hypervisor, and its answer is lost.
        $this->hypervisor->afterAResize = static function (): void {
            throw ComputeProviderException::requestFailed('fake', 'resize_vm', [], indeterminate: true);
        };
        $resize = $this->resizeJob($machine, vcpu: 4, memoryMib: 8192, diskGib: 400);
        $this->runWorker($resize);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $resize->refresh()->status);
        $this->assertSame(FailureClass::Timeout, $resize->failure_class);
        $this->assertSame(400, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->diskGib, 'The first attempt did not land.');
        $this->assertSame(40, $machine->refresh()->disk_gib);

        $this->retryAsOperator($resize)->assertOk();
        DB::table('provisioning_jobs')->where('id', $resize->id)->update(['next_attempt_at' => null]);
        $this->runWorker($resize);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->refresh()->status, (string) $resize->last_error);
        $this->assertSame(400, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->diskGib, 'The disk was grown twice.');

        // The row and the commitment follow what the hypervisor reported.
        $this->assertSame([4, 8192, 400], [$machine->refresh()->vcpu, $machine->memory_mib, $machine->disk_gib]);
        $live = NodeCapacityReservation::query()->whereNull('released_at')->sole();
        $this->assertSame([4, 8192, 400], [$live->vcpu, $live->memory_mib, $live->disk_gib]);
    }

    #[Test]
    public function a_disk_the_row_is_behind_is_grown_by_what_is_missing_from_the_machine(): void
    {
        $machine = $this->aBuiltMachine();

        // Half the growth landed out of band: the machine has 200, the row 40.
        $this->hypervisor->fleet->resizeVm('pve-01', (string) $machine->provider_id, new ResizeVmRequest(diskGib: 160));

        $resize = $this->resizeJob($machine, vcpu: 2, memoryMib: 4096, diskGib: 400);
        $this->runWorker($resize);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->refresh()->status, (string) $resize->last_error);
        $this->assertSame(400, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->diskGib);
        $this->assertSame(400, $machine->refresh()->disk_gib);
    }

    #[Test]
    public function a_machine_the_hypervisor_does_not_have_is_not_resized_and_nothing_is_committed(): void
    {
        $machine = $this->aBuiltMachine();
        $this->hypervisor->reportNoMachines = true;

        $resize = $this->resizeJob($machine, vcpu: 4, memoryMib: 8192, diskGib: 80);
        $this->runWorker($resize);

        $this->assertSame('vps.machine_not_found', $resize->refresh()->result['error']['code'] ?? null);
        $live = NodeCapacityReservation::query()->whereNull('released_at')->sole();
        $this->assertSame([2, 4096, 40], [$live->vcpu, $live->memory_mib, $live->disk_gib]);
        $this->assertSame(40, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->diskGib);
    }

    #[Test]
    public function a_disk_already_past_the_target_is_not_grown_again_and_the_row_follows_it(): void
    {
        $machine = $this->aBuiltMachine();

        // Grown by hand past what the plan sells: 150 on the machine, 40 on the row.
        $this->hypervisor->fleet->resizeVm('pve-01', (string) $machine->provider_id, new ResizeVmRequest(diskGib: 110));

        $resize = $this->resizeJob($machine, vcpu: 2, memoryMib: 4096, diskGib: 120);
        $this->runWorker($resize);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->refresh()->status, (string) $resize->last_error);
        $this->assertSame(150, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->diskGib);
        $this->assertSame(150, $machine->refresh()->disk_gib);
        $this->assertSame(150, NodeCapacityReservation::query()->whereNull('released_at')->sole()->disk_gib);
    }

    #[Test]
    public function vcpu_and_memory_the_machine_already_has_are_not_asked_for_again(): void
    {
        $machine = $this->aBuiltMachine();

        // The machine already has 4 / 8192; the row says 2 / 4096.
        $this->hypervisor->fleet->resizeVm('pve-01', (string) $machine->provider_id, new ResizeVmRequest(vcpu: 4, memoryMib: 8192));
        $this->hypervisor->failResizesWith = ComputeProviderException::requestFailed('fake', 'resize_vm', ['provider_message' => 'nothing should have been asked']);

        $resize = $this->resizeJob($machine, vcpu: 4, memoryMib: 8192, diskGib: 40);
        $this->runWorker($resize);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->refresh()->status, (string) $resize->last_error);
        $this->assertTrue($resize->result['response']['already_correct'] ?? false, json_encode($resize->result));
        $this->assertSame([4, 8192], [$machine->refresh()->vcpu, $machine->memory_mib]);
    }

    #[Test]
    public function a_look_that_fails_changes_nothing(): void
    {
        $machine = $this->aBuiltMachine();
        $this->hypervisor->failReadsWith = ComputeProviderException::requestFailed('fake', 'get_vm', ['provider_message' => 'node unreachable']);

        $resize = $this->resizeJob($machine, vcpu: 4, memoryMib: 8192, diskGib: 80);
        $this->runWorker($resize);
        $this->hypervisor->failReadsWith = null;

        $this->assertSame(FailureClass::Transient, $resize->refresh()->failure_class);
        $this->assertNotSame('provisioning.unclassified', $resize->result['error']['code'] ?? null);
        $this->assertSame([2, 4096, 40], [$machine->refresh()->vcpu, $machine->memory_mib, $machine->disk_gib]);
        $live = NodeCapacityReservation::query()->whereNull('released_at')->sole();
        $this->assertSame([2, 4096, 40], [$live->vcpu, $live->memory_mib, $live->disk_gib]);
        $this->assertSame(40, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->diskGib);
    }

    private function aBuiltMachine(): VirtualMachine
    {
        $job = $this->createJob();
        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status, (string) $job->last_error);

        return VirtualMachine::query()->sole();
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
}
