<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Exceptions\ClusterNotConfiguredException;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Provisioning\Application\Actions\CompensateFailedJob;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use PDOException;
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
    public function an_adoption_whose_hypervisor_cannot_be_asked_leaves_the_commitment_and_says_why(): void
    {
        $this->addNode('pve-02');
        $job = $this->createJob();
        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
        $machine = $this->hypervisor->everyMachine()[0];
        $before = $this->liveReservation();

        // Not a provider exception: the cluster's credentials are gone.
        $cluster = (string) $this->cluster->id;
        $this->hypervisor->atTheMomentOfLook = static function () use ($cluster): void {
            throw ClusterNotConfiguredException::missingCredentials($cluster, 'pve-kw');
        };

        $this->adoptAsOperator($job, $machine->providerId)->assertOk();

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status);
        $after = $this->liveReservation();
        $this->assertSame([$before->id, $before->node_id], [$after->id, $after->node_id]);
        $this->assertFalse($job->result['adoption']['capacity']['moved'] ?? null);
        $this->assertStringContainsString('could not be asked', (string) ($job->result['adoption']['capacity']['reason'] ?? ''));
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

    #[Test]
    public function a_build_whose_commitment_was_released_is_committed_again_where_the_adopted_machine_is(): void
    {
        /*
         * The build failed and its commitment was given back (the engine's
         * release on a failure it took for "nothing was built"), and then the
         * machine was found at the provider and adopted. Adoption found no
         * live reservation and did nothing: the machine ran on pve-01,
         * charged to no node, until its next resize wrote one.
         */
        $this->addNode('pve-02');
        $job = $this->createJob();
        $this->runWorker($job);
        $machine = $this->hypervisor->everyMachine()[0];
        $this->assertSame('pve-01', $machine->nodeName);

        $job->refresh()->forceFill(['status' => ProvisioningJobStatus::Failed, 'failure_class' => FailureClass::Permanent])->save();
        app(CompensateFailedJob::class)->execute($job, FailureClass::Permanent);
        $this->assertSame(0, NodeCapacityReservation::query()->whereNull('released_at')->count(), 'The build still holds a commitment, so nothing is measured.');
        $this->assertCommitted($this->node, vms: 0, memoryMib: 0, diskGib: 0);

        $this->adoptAsOperator($job, $machine->providerId)->assertOk();

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status);
        $reservation = $this->liveReservation();
        $this->assertSame((string) $this->node->id, $reservation->node_id, 'The adopted machine is charged to no node.');
        $this->assertSame([2, 4096, 40], [$reservation->vcpu, $reservation->memory_mib, $reservation->disk_gib]);
        $this->assertSame($job->service_id, $reservation->service_id);
        $this->assertSame((string) $this->poolOn($this->node)->id, $reservation->storage_id);
        $this->assertCommitted($this->node, vms: 1, memoryMib: 4096, diskGib: 40);
        $this->assertSame(40, (int) $this->poolOn($this->node)->committed_gib);
        $this->assertTrue($job->result['adoption']['capacity']['recommitted'] ?? null, json_encode($job->result['adoption'] ?? null));
    }

    #[Test]
    public function a_released_build_is_committed_again_on_the_node_the_machine_is_found_on_not_where_it_was_last_placed(): void
    {
        // The D5 build: its commitment ends on pve-02, the machine on pve-01.
        $other = $this->addNode('pve-02');
        $job = $this->createJob();
        $this->hypervisor->loseTheRequestToCreates = true;
        $this->runWorker($job);
        $first = $this->hypervisor->creates[0];
        $this->node->forceFill(['status' => NodeStatus::Maintenance])->save();
        $this->hypervisor->loseTheRequestToCreates = false;
        $this->hypervisor->loseTheAnswerToCreates = false;
        $this->retryAsOperator($job)->assertOk();
        $this->hypervisor->atTheMomentOfCreate = function (CreateVmRequest $request) use ($first): void {
            if ($request->nodeName === 'pve-02') {
                $this->hypervisor->atTheMomentOfCreate = null;
                $this->hypervisor->fleet->createVirtualMachine($first);
            }
        };
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
        $this->runWorker($job);
        $this->assertSame((string) $other->id, $this->liveReservation()->node_id);

        // And then it is given back, as a failure would.
        $job->refresh()->forceFill(['status' => ProvisioningJobStatus::Failed, 'failure_class' => FailureClass::Permanent])->save();
        app(CompensateFailedJob::class)->execute($job, FailureClass::Permanent);
        $this->assertSame(0, NodeCapacityReservation::query()->whereNull('released_at')->count());

        $this->adoptAsOperator($job, (string) $first->vmId)->assertOk();

        $this->assertSame((string) $this->node->id, $this->liveReservation()->node_id, 'The machine was committed where the build was last placed, not where it runs.');
        $this->assertCommitted($this->node, vms: 1, memoryMib: 4096, diskGib: 40);
        $this->assertCommitted($other, vms: 0, memoryMib: 0, diskGib: 0);
    }

    #[Test]
    public function a_released_build_whose_hypervisor_cannot_be_asked_is_committed_where_it_was_last_placed_and_says_why(): void
    {
        $this->addNode('pve-02');
        $job = $this->createJob();
        $this->runWorker($job);
        $machine = $this->hypervisor->everyMachine()[0];
        $job->refresh()->forceFill(['status' => ProvisioningJobStatus::Failed, 'failure_class' => FailureClass::Permanent])->save();
        app(CompensateFailedJob::class)->execute($job, FailureClass::Permanent);

        $cluster = (string) $this->cluster->id;
        $this->hypervisor->atTheMomentOfLook = static function () use ($cluster): void {
            throw ClusterNotConfiguredException::missingCredentials($cluster, 'pve-kw');
        };

        $this->adoptAsOperator($job, $machine->providerId)->assertOk();

        $this->assertSame((string) $this->node->id, $this->liveReservation()->node_id);
        $this->assertCommitted($this->node, vms: 1, memoryMib: 4096, diskGib: 40);
        $this->assertStringContainsString('could not be asked', (string) ($job->refresh()->result['adoption']['capacity']['reason'] ?? ''));
    }

    #[Test]
    public function a_build_released_twice_is_committed_again_from_its_last_commitment(): void
    {
        /*
         * Failed on pve-01 (released), retried by an operator onto pve-02
         * at a larger shape and failed again there (released), then adopted
         * with a hypervisor that cannot be asked. The commitment taken again
         * is the build's last: pve-02, at the shape it last held - not the
         * first row the key ever had.
         */
        $other = $this->addNode('pve-02');
        $job = $this->createJob(attributes: ['status' => ProvisioningJobStatus::Failed, 'failure_class' => FailureClass::Permanent]);

        NodeCapacityReservation::query()->create([
            'reservation_key' => $job->idempotency_key, 'node_id' => $this->node->id, 'service_id' => $job->service_id,
            'customer_id' => $job->customer_id, 'vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40,
            'released_at' => now()->subHour(),
        ]);
        NodeCapacityReservation::query()->create([
            'reservation_key' => $job->idempotency_key, 'node_id' => $other->id, 'service_id' => $job->service_id,
            'customer_id' => $job->customer_id, 'vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80,
            'released_at' => now()->subMinute(),
        ]);

        $cluster = (string) $this->cluster->id;
        $this->hypervisor->atTheMomentOfLook = static function () use ($cluster): void {
            throw ClusterNotConfiguredException::missingCredentials($cluster, 'pve-kw');
        };

        $this->adoptAsOperator($job, '4242')->assertOk();

        $live = $this->liveReservation();
        $this->assertSame((string) $other->id, $live->node_id, 'The commitment was taken again from the first release, not the last.');
        $this->assertSame([4, 8192, 80], [$live->vcpu, $live->memory_mib, $live->disk_gib]);
        $this->assertCommitted($other, vms: 1, memoryMib: 8192, diskGib: 80);
        $this->assertCommitted($this->node, vms: 0, memoryMib: 0, diskGib: 0);
    }

    #[Test]
    public function a_database_error_in_the_lookup_is_not_taken_for_a_hypervisor_that_cannot_be_asked(): void
    {
        /*
         * Any failure to ask the hypervisor leaves the commitment where it is
         * and the adoption goes on. A failure of the platform's own database
         * is not that: the adoption's transaction cannot go on, and it is
         * rolled back rather than recorded over a lookup that never ran.
         */
        $this->addNode('pve-02');
        $job = $this->createJob();
        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
        $machine = $this->hypervisor->everyMachine()[0];

        $this->hypervisor->atTheMomentOfLook = static function (): void {
            throw new QueryException('pgsql', 'select 1', [], new PDOException('SQLSTATE[08006]: connection failure'));
        };

        $this->withoutExceptionHandling();

        try {
            $this->adoptAsOperator($job, $machine->providerId);
            $this->fail('A database error in the adoption lookup was swallowed.');
        } catch (QueryException) {
            // Re-thrown, as it should be.
        }

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status, 'The adoption was recorded over a database error.');
        $this->assertArrayNotHasKey('adoption', $job->result ?? []);
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
