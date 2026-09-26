<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use Lynomia\Modules\Vps\Infrastructure\NodeCapacityReleaser;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * An operator's retry of a VPS create that stopped after it had committed
 * capacity.
 *
 * Any settle other than a timeout's releases the capacity an attempt
 * committed — the reservation row is stamped released, and kept. The operator
 * clears whatever stopped the build and retries. The retry has to be able to
 * commit capacity again under the same key, since the key is the job's and a
 * retry is the same job; it used to find no live reservation, try to write
 * one, and trip the unique index the released row still held — back to review
 * (or, after a permanent failure, back to the queue and into the same
 * violation) with a database error, and no way out.
 *
 * Four roads into that state, each driven to a built machine: refusals that
 * exhaust the automatic retries, an exhausted address pool later extended
 * (the case RetryProvisioningJob's docblock names as recoverable), a network
 * that could not be attached until an operator gave it a bridge, and F-15's
 * own repoint flow once the stranger was found after capacity was committed.
 */
final class RetryingACreateAfterItsRetriesRanOutTest extends TestCase
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
    public function a_create_refused_until_its_retries_ran_out_is_built_by_the_operators_retry(): void
    {
        $job = $this->createJob();

        $this->hypervisor->atTheMomentOfCreate = static function (CreateVmRequest $request): void {
            throw ComputeProviderException::requestFailed('fake', 'create_vm', [
                'node' => $request->nodeName,
                'vmid' => $request->vmId,
                'provider_message' => 'the cluster refused this create for now',
            ]);
        };

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
            $this->runWorker($job);
        }

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status, (string) $job->last_error);
        $this->assertSame(3, $job->attempts);

        $reservation = NodeCapacityReservation::query()->where('reservation_key', $job->idempotency_key)->sole();
        $this->assertFalse($reservation->isLive(), 'settling in review did not give the capacity back');
        $this->assertSame(0, $this->node->refresh()->vm_count);

        // The refusal is gone; the operator retries.
        $this->hypervisor->atTheMomentOfCreate = null;
        $this->assertTheOperatorsRetryBuildsItOnce($job);
    }

    #[Test]
    public function a_create_that_ran_out_of_addresses_is_built_once_the_pool_is_extended(): void
    {
        IpAddress::query()->update(['status' => IpAddressStatus::Unavailable]);
        $job = $this->createJob();

        $this->runUntilItStops($job);

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status, (string) $job->last_error);
        $this->assertSame('ipam.pool_exhausted', $job->result['error']['code'] ?? null);
        $this->assertFalse(NodeCapacityReservation::query()->where('reservation_key', $job->idempotency_key)->sole()->isLive());

        IpAddress::query()->update(['status' => IpAddressStatus::Available]);
        $this->assertTheOperatorsRetryBuildsItOnce($job);
    }

    #[Test]
    public function a_create_with_nowhere_to_attach_is_built_once_the_network_has_a_bridge(): void
    {
        Network::query()->update(['bridge' => null]);
        $job = $this->createJob();

        $this->runUntilItStops($job);

        $this->assertSame(ProvisioningJobStatus::Failed, $job->status, (string) $job->last_error);
        $this->assertSame('vps.network_not_attachable', $job->result['error']['code'] ?? null);
        $this->assertFalse(NodeCapacityReservation::query()->where('reservation_key', $job->idempotency_key)->sole()->isLive());

        Network::query()->update(['bridge' => 'vmbr1']);
        $this->assertTheOperatorsRetryBuildsItOnce($job);
    }

    #[Test]
    public function a_create_repointed_after_it_committed_capacity_is_built_under_the_new_identity(): void
    {
        /*
         * F-15's own way out, taken after capacity was committed: the
         * stranger holds the derived id on a second, active node, so the
         * create is refused at the id after reserving — found there, by this
         * attempt or the next, as somebody else's. The repoint is accepted;
         * the retry has to be able to commit capacity again.
         */
        $this->addNode('pve-02');
        $job = $this->createJob();
        $taken = $this->derivedIdOf($job);
        $this->aStrangerAt($taken, node: 'pve-02');

        $this->runUntilItStops($job);

        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null, (string) $job->last_error);
        $this->assertTrue(NodeCapacityReservation::query()->where('reservation_key', $job->idempotency_key)->exists());
        $this->assertSame(0, NodeCapacityReservation::query()->where('reservation_key', $job->idempotency_key)->whereNull('released_at')->count());

        $this->repointAsOperator($job)->assertOk();
        $this->assertTheOperatorsRetryBuildsItOnce($job);

        $this->assertNotSame($taken, $this->machinesNamed('web-01')[0]->providerId);
        $this->assertCount(1, $this->machinesNamed('someone-elses-box'), 'the stranger was touched');
    }

    #[Test]
    public function a_key_is_committed_once_while_live_and_once_again_after_release(): void
    {
        $resources = new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 40);
        $reserve = app(ReserveNodeCapacity::class);

        $reserve->execute($this->node, $resources, reservationKey: 'job-key-1');
        $reserve->execute($this->node, $resources, reservationKey: 'job-key-1');
        $this->assertSame(1, $this->node->refresh()->vm_count, 'a live key committed twice');

        NodeCapacityReservation::query()->where('reservation_key', 'job-key-1')->update(['released_at' => now()]);
        DB::table('compute_nodes')->where('id', $this->node->id)->update([
            'vm_count' => 0, 'allocated_cpu_cores' => 0, 'allocated_memory_mib' => 0, 'allocated_storage_gib' => 0,
        ]);

        $reserve->execute($this->node, $resources, reservationKey: 'job-key-1');
        $reserve->execute($this->node, $resources, reservationKey: 'job-key-1');

        $this->assertSame(1, $this->node->refresh()->vm_count);
        $this->assertSame(1, NodeCapacityReservation::query()->where('reservation_key', 'job-key-1')->whereNull('released_at')->count());
    }

    #[Test]
    public function the_database_refuses_a_second_live_reservation_for_one_key(): void
    {
        /*
         * The index is the real guard against two workers that both found no
         * live row: whichever writes second is refused, and its transaction
         * rolls back with its counter increment.
         */
        $row = [
            'node_id' => $this->node->id,
            'reservation_key' => 'job-key-2',
            'vcpu' => 1,
            'memory_mib' => 1024,
            'disk_gib' => 10,
        ];

        NodeCapacityReservation::query()->create($row);

        $this->expectException(UniqueConstraintViolationException::class);

        NodeCapacityReservation::query()->create($row);
    }

    #[Test]
    public function a_release_gives_back_the_live_reservation_not_a_released_one(): void
    {
        $job = $this->createJob();
        $resources = new VmResources(vcpu: 2, memoryMib: 4096, diskGib: 40);

        NodeCapacityReservation::query()->create([
            'node_id' => $this->node->id,
            'reservation_key' => $job->idempotency_key,
            'vcpu' => 2,
            'memory_mib' => 4096,
            'disk_gib' => 40,
            'released_at' => now()->subHour(),
        ]);
        app(ReserveNodeCapacity::class)->execute($this->node, $resources, reservationKey: $job->idempotency_key);
        $this->assertSame(1, $this->node->refresh()->vm_count);

        app(NodeCapacityReleaser::class)->release($job);

        $this->assertSame(0, $this->node->refresh()->vm_count, 'the release read the released row and gave nothing back');
        $this->assertSame(0, NodeCapacityReservation::query()->where('reservation_key', $job->idempotency_key)->whereNull('released_at')->count());
    }

    private function runUntilItStops(ProvisioningJob $job): void
    {
        for ($attempt = 1; $attempt <= $job->max_attempts; $attempt++) {
            DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
            $this->runWorker($job);

            if ($job->refresh()->status !== ProvisioningJobStatus::Queued) {
                return;
            }
        }
    }

    /**
     * The operator's retry, one run, and a machine built with one machine's
     * worth of capacity committed by one live reservation — the released ones
     * kept as history.
     */
    private function assertTheOperatorsRetryBuildsItOnce(ProvisioningJob $job): void
    {
        $this->retryAsOperator($job)->assertOk();
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status, (string) $job->last_error);
        $this->assertCount(1, $this->machinesNamed('web-01'));

        $live = NodeCapacityReservation::query()
            ->where('reservation_key', $job->idempotency_key)
            ->whereNull('released_at')
            ->sole();
        $this->assertGreaterThanOrEqual(2, NodeCapacityReservation::query()->where('reservation_key', $job->idempotency_key)->count());

        $committed = DB::table('compute_nodes')
            ->selectRaw('sum(vm_count) as machines, sum(allocated_cpu_cores) as cpu, sum(allocated_memory_mib) as memory, sum(allocated_storage_gib) as disk')
            ->first();
        $this->assertSame(1, (int) $committed->machines);
        $this->assertSame(2, (int) $committed->cpu);
        $this->assertSame(4096, (int) $committed->memory);
        $this->assertSame(40, (int) $committed->disk);

        $machine = $this->machinesNamed('web-01')[0];
        $this->assertSame($machine->nodeName, DB::table('compute_nodes')->where('id', $live->node_id)->value('provider_name'));
    }
}
