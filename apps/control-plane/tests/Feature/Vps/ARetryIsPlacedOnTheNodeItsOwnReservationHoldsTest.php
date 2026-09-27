<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * A retried build is placed without its own commitment counted against it
 * (D3, round six).
 *
 * A build's first attempt commits its capacity, keyed on the job, and a
 * transient refusal carries that commitment to the next attempt. The next
 * attempt is placed afresh, and the scheduler counted the job's own live
 * commitment as somebody else's: on a node the machine fills more than half
 * of, the retry saw no room and was refused on every attempt ("No active node
 * can host"), ending in review with the order paid - the only node that could
 * build it being the one it already held.
 *
 * The job's own reservation is not counted against the node and pool it
 * holds; a stranger's still is.
 */
final class ARetryIsPlacedOnTheNodeItsOwnReservationHoldsTest extends TestCase
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
    public function a_retry_is_built_on_the_node_its_own_reservation_fills(): void
    {
        $job = $this->createJob(['memory_mib' => 60000]);
        $this->refuseTheFirstCreate();

        $this->drive($job);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status, (string) $job->last_error);
        $this->assertCount(2, $this->hypervisor->creates);

        // One commitment, the build's own, on the node it is built on.
        $reservation = NodeCapacityReservation::query()->whereNull('released_at')->sole();
        $this->assertSame((string) $this->node->id, $reservation->node_id);
        $node = $this->node->refresh();
        $this->assertSame([1, 60000], [(int) $node->vm_count, (int) $node->allocated_memory_mib]);
    }

    #[Test]
    public function another_builds_reservation_still_fills_the_node(): void
    {
        $first = $this->createJob(['memory_mib' => 60000]);
        $this->runWorker($first);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $first->refresh()->status, (string) $first->last_error);

        $second = $this->createJob(['memory_mib' => 60000, 'hostname' => 'web-02']);
        $this->runWorker($second);

        $this->assertSame(ProvisioningJobStatus::Queued, $second->refresh()->status);
        $this->assertSame(FailureClass::Capacity, $second->failure_class);
        // Turned away by the scheduler, which measured the node with the
        // first build's commitment on it - not only by the re-check under
        // the lock after the scheduler had placed it there.
        $this->assertSame('compute.no_capacity_available', $second->result['error']['code'] ?? null);
        $this->assertSame(60000, (int) $this->node->refresh()->allocated_memory_mib);
    }

    private function refuseTheFirstCreate(): void
    {
        $calls = 0;
        $this->hypervisor->atTheMomentOfCreate = static function (CreateVmRequest $request) use (&$calls): void {
            if (++$calls === 1) {
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
}
