<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * A create the cluster refused out loud is retried with its capacity kept —
 * and the retry may be placed on another node.
 *
 * The capacity used to stay where the first attempt reserved it. The retry
 * was placed on node B, the reservation (keyed on the job) was found live on
 * node A and returned as "already committed", and the machine was built on B:
 * A carried a machine it did not have until the machine was destroyed, and B
 * carried one it was not charged for, so the scheduler went on selling B's
 * memory to the next order. The node a machine is built on has to be the node
 * that holds its reservation.
 */
final class ACreateRetriedOnAnotherNodeTest extends TestCase
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
    public function the_capacity_moves_with_the_retry_to_the_node_it_is_built_on(): void
    {
        $other = $this->addNode('pve-02');
        $job = $this->createJob();

        $this->refuseTheFirstCreateOn('pve-01');
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::Queued, $job->fresh()->status);

        /*
         * pve-01 leaves placement between the attempts, so the retry goes
         * elsewhere. This used to need nothing: the scheduler counted the
         * build's own commitment against pve-01, which made it look busier
         * than pve-02 - the defect that kept a retry off the one node that
         * could hold it (D3, ARetryIsPlacedOnTheNodeItsOwnReservationHoldsTest).
         * Measured without it the two nodes are alike, and the retry would
         * rightly stay where its commitment already is.
         */
        $this->node->forceFill(['status' => NodeStatus::Maintenance])->save();
        $this->drive($job);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()->status);

        $vm = VirtualMachine::query()->sole();
        $this->assertSame($other->getKey(), $vm->node_id, 'The retry was expected to be placed on the other node.');

        $live = NodeCapacityReservation::query()->whereNull('released_at')->sole();
        $this->assertSame($vm->node_id, $live->node_id, 'The machine is built on one node and its capacity is reserved on another.');

        $this->assertCharged($other, 1, 2, 4096, 40);
        $this->assertCharged($this->node, 0, 0, 0, 0);

        $this->assertSame(40, (int) $this->storageOf($other)->committed_gib);
        $this->assertSame(0, (int) $this->storageOf($this->node)->committed_gib);
    }

    #[Test]
    public function the_node_the_retry_is_built_on_is_not_sold_again(): void
    {
        // Room for exactly one 4 GiB machine on the second node.
        $small = $this->addNode('pve-02');
        $small->forceFill(['memory_mib' => 6144])->save();

        $job = $this->createJob();

        $this->refuseTheFirstCreateOn('pve-01');
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::Queued, $job->fresh()->status);

        // The first node leaves placement between the attempts, so the retry
        // can only go to the small one.
        $this->node->forceFill(['status' => NodeStatus::Maintenance])->save();
        $this->drive($job);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()->status);
        $this->assertSame($small->getKey(), VirtualMachine::query()->sole()->node_id);

        // The next order for that node must be told it is full: it already
        // runs the machine the retry built there.
        $this->expectException(NodeCapacityExceededException::class);
        app(ReserveNodeCapacity::class)->execute(
            $small->fresh(),
            new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 40),
            reservationKey: 'the-next-order',
        );
    }

    private function refuseTheFirstCreateOn(string $nodeName): void
    {
        $calls = 0;
        $this->hypervisor->atTheMomentOfCreate = function (CreateVmRequest $request) use (&$calls, $nodeName): void {
            $calls++;

            if ($calls === 1) {
                $this->assertSame($nodeName, $request->nodeName, 'The first attempt was expected on '.$nodeName.'.');

                throw ComputeProviderException::requestFailed('fake', 'create_vm', [
                    'node' => $request->nodeName,
                    'vmid' => $request->vmId,
                    'provider_message' => 'transient refusal',
                ]);
            }
        };
    }

    private function drive(ProvisioningJob $job): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
            $this->runWorker($job);
            $job->refresh();

            if ($job->status !== ProvisioningJobStatus::Queued) {
                return;
            }
        }
    }

    private function assertCharged(ComputeNode $node, int $machines, int $cpu, int $memory, int $disk): void
    {
        $node = $node->fresh();

        $this->assertSame(
            [$machines, $cpu, $memory, $disk],
            [(int) $node->vm_count, (int) $node->allocated_cpu_cores, (int) $node->allocated_memory_mib, (int) $node->allocated_storage_gib],
            $node->provider_name.' is charged for the wrong machines.',
        );
    }

    private function storageOf(ComputeNode $node): ComputeStorage
    {
        return ComputeStorage::query()->where('node_id', $node->getKey())->sole();
    }
}
