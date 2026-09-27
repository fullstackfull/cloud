<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * An adoption moves the build's commitment to the node the adopted machine is
 * on (D5, round six).
 *
 * A build's first create was lost on pve-01, pve-01 left placement, and the
 * operator's retry moved the commitment to pve-02 and created there - at the
 * moment the first create landed on pve-01. The retry found its own build on
 * pve-01 and stopped for review, and the operator adopted it. Adoption never
 * touched capacity: the machine ran on pve-01, charged nothing, while pve-02
 * went on carrying a commitment for a machine it does not run, which the
 * scheduler would never sell again.
 *
 * Adoption now moves the live reservation to the adopted machine's node and a
 * pool there, in the one lock order, and says so on the job's adoption record.
 */
final class AnAdoptionMovesTheCommitmentToWhereTheMachineIsTest extends TestCase
{
    use DrivesVpsCreatesThroughTheOperatorPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->setUpTheCluster();
    }

    #[Test]
    public function a_machine_adopted_on_another_node_than_its_reservation_takes_the_commitment_with_it(): void
    {
        $other = $this->addNode('pve-02');
        $job = $this->createJob();

        // Attempt 1 on pve-01: the create is sent and lost.
        $this->hypervisor->loseTheRequestToCreates = true;
        $this->runWorker($job);
        $first = $this->hypervisor->creates[0];
        $this->assertSame('pve-01', $first->nodeName);

        // pve-01 leaves placement; the operator retries.
        $this->node->forceFill(['status' => NodeStatus::Maintenance])->save();
        $this->hypervisor->loseTheRequestToCreates = false;
        $this->hypervisor->loseTheAnswerToCreates = false;
        $this->retryAsOperator($job)->assertOk();

        // Attempt 1's create lands on pve-01 as attempt 2 creates on pve-02.
        $this->hypervisor->atTheMomentOfCreate = function (CreateVmRequest $request) use ($first): void {
            if ($request->nodeName === 'pve-02') {
                $this->hypervisor->atTheMomentOfCreate = null;
                $this->hypervisor->fleet->createVirtualMachine($first);
            }
        };
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
        $this->runWorker($job);

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
        $this->assertSame((string) $other->id, $this->liveReservation()->node_id, 'The retry did not move the commitment to pve-02.');

        $this->adoptAsOperator($job, (string) $first->vmId)->assertOk();

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status);
        $reservation = $this->liveReservation();
        $this->assertSame((string) $this->node->id, $reservation->node_id, 'The commitment stayed on a node the adopted machine is not on.');
        $this->assertSame((string) $this->poolOn($this->node)->id, $reservation->storage_id);

        $this->assertCommitted($this->node, vms: 1, memoryMib: 4096, diskGib: 40);
        $this->assertCommitted($other, vms: 0, memoryMib: 0, diskGib: 0);
        $this->assertSame(40, (int) $this->poolOn($this->node)->committed_gib);
        $this->assertSame(0, (int) $this->poolOn($other)->committed_gib);

        $this->assertSame('pve-01', $job->result['adoption']['capacity']['node'] ?? null, json_encode($job->result['adoption'] ?? null));
    }

    #[Test]
    public function a_machine_adopted_where_its_reservation_is_leaves_the_commitment_alone(): void
    {
        $this->addNode('pve-02');
        $job = $this->createJob();

        // The answer is lost, the machine is built on the node the
        // commitment is on, and the job stops for review.
        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
        $machine = $this->hypervisor->everyMachine()[0];
        $before = $this->liveReservation();

        $this->adoptAsOperator($job, $machine->providerId)->assertOk();

        $after = $this->liveReservation();
        $this->assertSame([$before->id, $before->node_id, $before->storage_id], [$after->id, $after->node_id, $after->storage_id]);
        $this->assertCommitted($this->node, vms: 1, memoryMib: 4096, diskGib: 40);
    }

    private function liveReservation(): NodeCapacityReservation
    {
        return NodeCapacityReservation::query()->whereNull('released_at')->sole();
    }

    private function poolOn(ComputeNode $node): ComputeStorage
    {
        return ComputeStorage::query()->where('node_id', $node->id)->sole();
    }

    private function assertCommitted(ComputeNode $node, int $vms, int $memoryMib, int $diskGib): void
    {
        $node = $node->refresh();

        $this->assertSame(
            [$node->provider_name, $vms, $memoryMib, $diskGib],
            [$node->provider_name, (int) $node->vm_count, (int) $node->allocated_memory_mib, (int) $node->allocated_storage_gib],
        );
    }
}
