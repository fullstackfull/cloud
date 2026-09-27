<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\ReleaseNodeCapacity;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Application\Handlers\ResizeVpsHandler;
use Lynomia\Modules\Vps\Application\Services\MachineCommitment;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * The plan-change quote and the resize ask one capacity question, when the
 * machine and its row disagree (the verification of round seven D, q1/q2).
 *
 * The resize reads the machine from the hypervisor before it grows it, and
 * asks the node only about what the target adds above what the machine
 * runs. The quote measured from the row: a machine behind its row (row 4 /
 * 8192, hypervisor 2 / 4096, no live commitment) passed the quote and was
 * refused for capacity by the resize after the money moved (q2); a machine
 * ahead of its row was refused by the quote a change the resize would
 * deliver (q1). The quote now reads the machine from the hypervisor too, and
 * asks the same dry run; when the hypervisor cannot be read it asks the
 * stricter question. A quote that passes is a change the resize does not
 * refuse for capacity.
 */
final class TheQuoteAndTheResizeAskOneCapacityQuestionTest extends TestCase
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
    public function a_machine_ahead_of_its_row_is_not_refused_a_change_the_resize_delivers(): void
    {
        // Row and commitment 2 / 4096 / 160; the machine already 4 / 8192 / 160.
        $machine = $this->aMachine(4000);
        $this->hypervisor->fleet->resizeVm('pve-01', (string) $machine->provider_id, new ResizeVmRequest(vcpu: 4, memoryMib: 8192));
        $this->tightNode();

        $quote = $this->quote($machine, 4, 8192, 200);
        $resize = $this->resize($machine, 4, 8192, 200);

        $this->assertNull($quote, 'The quote refused a change the machine already mostly has.');
        $this->assertTrue($resize->successful, (string) $resize->errorMessage);
        $this->assertSame(200, $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->diskGib);
    }

    #[Test]
    public function a_target_the_machine_already_meets_is_not_refused(): void
    {
        $machine = $this->aMachine(4001);
        $this->hypervisor->fleet->resizeVm('pve-01', (string) $machine->provider_id, new ResizeVmRequest(vcpu: 4, memoryMib: 8192, diskGib: 40));
        $this->tightNode();

        $this->assertNull($this->quote($machine, 4, 8192, 200));
        $this->assertTrue($this->resize($machine, 4, 8192, 200)->successful);
    }

    #[Test]
    public function a_machine_behind_its_row_with_no_commitment_gets_one_answer_from_both(): void
    {
        // The row says 4 / 8192; the machine runs 2 / 4096; nothing is held.
        $machine = $this->aMachine(4002);
        $held = NodeCapacityReservation::query()->whereNull('released_at')->sole();
        app(ReleaseNodeCapacity::class)->execute($this->node, $held->resources(), $held->storage_id, $held->reservation_key);
        $machine->forceFill(['vcpu' => 4, 'memory_mib' => 8192])->save();
        $this->tightNode();

        $quote = $this->quote($machine, 4, 8192, 160);
        $resize = $this->resize($machine, 4, 8192, 160);

        // 4096 MiB above what runs does not fit this node: both refuse it,
        // the quote before the money moves. (Measured from the row, the
        // quote saw no growth and passed it.)
        $this->assertNotNull($quote, 'The quote passed a growth the resize refuses for capacity.');
        $this->assertSame(FailureClass::Capacity, $resize->failureClass, (string) $resize->errorMessage);
    }

    #[Test]
    public function a_growth_the_node_cannot_hold_is_refused_by_both(): void
    {
        $machine = $this->aMachine(4003);
        $this->tightNode();

        $this->assertNotNull($this->quote($machine, 4, 8192, 160));
        $resize = $this->resize($machine, 4, 8192, 160);
        $this->assertSame(FailureClass::Capacity, $resize->failureClass);
    }

    #[Test]
    public function a_quote_that_cannot_read_the_hypervisor_asks_the_stricter_question(): void
    {
        /*
         * The machine is behind its row and nothing is held: read, only the
         * growth above what it runs is asked; unread, the quote cannot know
         * what it runs, and asks the whole growth above what is held.
         */
        $machine = $this->aMachine(4004);
        $held = NodeCapacityReservation::query()->whereNull('released_at')->sole();
        app(ReleaseNodeCapacity::class)->execute($this->node, $held->resources(), $held->storage_id, $held->reservation_key);
        $machine->forceFill(['vcpu' => 4, 'memory_mib' => 8192])->save();
        DB::table('compute_nodes')->where('id', $this->node->id)->update(['memory_mib' => 7000, 'memory_headroom_percent' => 10]);

        // Read: it runs 4096, and the 4096 above that fits in 6300.
        $this->assertNull($this->quote($machine, 4, 8192, 160));

        // Unread: the row says 8192 already, but the machine may run less -
        // it does - so the whole 8192 is asked of 6300, and refused. Measured
        // from the row it would pass, and the resize might refuse it.
        $this->hypervisor->failReadsWith = ComputeProviderException::requestFailed('fake', 'get_vm', ['provider_message' => 'node unreachable']);
        $this->assertNotNull($this->quote($machine, 4, 8192, 160));
        $this->hypervisor->failReadsWith = null;
    }

    /**
     * A machine the platform built, at 2 / 4096 / 40, committed on pve-01.
     */
    private function aMachine(int $providerId): VirtualMachine
    {
        $service = Service::factory()->active()->create(['customer_id' => $this->customer->id, 'kind' => 'vps']);
        $this->aStrangerAt((string) $providerId, name: 'web-'.$providerId);
        $this->hypervisor->fleet->resizeVm('pve-01', (string) $providerId, new ResizeVmRequest(vcpu: 2, memoryMib: 4096));
        $state = $this->hypervisor->fleet->getVm('pve-01', (string) $providerId);
        $this->assertSame([2, 4096, 160], [$state?->vcpu, $state?->memoryMib, $state?->diskGib]);
        // The stranger helper builds 160 GiB; bring the row and commitment to what it has.
        $machine = VirtualMachine::factory()->onNode($this->node, $providerId)->forService($service)->resources(2, 4096, 160)->create(['storage_name' => 'local-nvme']);
        $pool = ComputeStorage::query()->where('node_id', $this->node->id)->sole();
        app(ReserveNodeCapacity::class)->execute($this->node, new VmResources(2, 4096, 160), customerId: $this->customer->id, storageId: $pool->id, reservationKey: 'build:'.$service->id, serviceId: $service->id);

        return $machine;
    }

    private function tightNode(): void
    {
        DB::table('compute_nodes')->where('id', $this->node->id)->update(['memory_mib' => 5000, 'memory_headroom_percent' => 10]);
        $this->node->refresh();
    }

    private function quote(VirtualMachine $machine, int $vcpu, int $memoryMib, int $diskGib): ?string
    {
        return app(MachineCommitment::class)->whyTheGrowthWouldNotFit($machine->refresh(), $vcpu, $memoryMib, $diskGib);
    }

    private function resize(VirtualMachine $machine, int $vcpu, int $memoryMib, int $diskGib): ProvisioningResult
    {
        return app(ResizeVpsHandler::class)->execute(ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $this->customer->id,
            'kind' => ProvisioningJobKind::Resize,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Running,
            'payload' => ['virtual_machine_id' => (string) $machine->id, 'vcpu' => $vcpu, 'memory_mib' => $memoryMib, 'disk_gib' => $diskGib],
        ]));
    }
}
