<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Application\Handlers\ResizeVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\Feature\Vps\Doubles\AnswerLosingComputeProvider;
use Tests\TestCase;

/**
 * A resize the hypervisor answers with a task that has not finished is read
 * back only once the task has (D8-1, the re-audit after round seven).
 *
 * Proxmox answers a disk growth with a UPID, which the adapter reports as
 * RemoteTaskStatus::Running. The handler ignored the status and read the
 * machine back at once: it reported the disk it had, the job succeeded with
 * the old disk recorded on the row and the commitment, and the machine went
 * on to grow to 400 GiB on a node committed for 40. The controlled
 * hypervisor now models that answer (a configured task delay): the growth
 * lands when a read finds its task finished, as getTask() reads the UPID.
 *
 * Driven through the engine. The fleet is kept in a file so a second
 * instance of the simulator - one with no delay, for which the task has
 * finished - can stand for time passing.
 */
final class AResizeAnsweredWithARunningTaskIsReadBackOnlyOnceItHasFinishedTest extends TestCase
{
    use DrivesVpsCreatesThroughTheOperatorPath;
    use RefreshDatabase;

    private string $fleetPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->fleetPath = storage_path('framework/testing/fake-fleet-'.uniqid().'.json');
        config()->set('compute.fake.state_path', $this->fleetPath);
        // Every task this simulator starts is still running for ten minutes.
        config()->set('compute.fake.task_delay_seconds', 600);

        $this->setUpTheCluster();
        $this->hypervisor->loseTheAnswerToCreates = false;
    }

    protected function tearDown(): void
    {
        if (is_file($this->fleetPath)) {
            unlink($this->fleetPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function the_row_is_written_once_the_task_has_finished_and_the_disk_is_grown_once(): void
    {
        $machine = $this->aBuiltMachine();
        $resize = $this->resizeJob($machine, vcpu: 4, memoryMib: 8192, diskGib: 400);

        $this->runWorker($resize);

        // Answered, not finished: nothing is recorded as the outcome.
        $resize->refresh();
        $this->assertSame(ProvisioningJobStatus::Queued, $resize->status, 'A resize whose task was still running was settled: '.$resize->last_error);
        $this->assertSame(ResizeVpsHandler::IN_PROGRESS, $resize->result['error']['code'] ?? null);
        $this->assertSame(FailureClass::Transient, $resize->failure_class);
        $this->assertIsArray($resize->result[ResizeVpsHandler::TASK_IN_FLIGHT] ?? null);
        $this->assertSame([2, 4096, 40], $this->rowShape($machine));
        $this->assertSame(40, $this->fleetDisk($machine), 'The simulator grew the disk while reporting its task running.');
        // The commitment is the ceiling meanwhile: the machine is one shape or the other.
        $this->assertSame([4, 8192, 400], $this->committed());

        // Asked again while the task still runs: asked about, not asked again.
        $this->runWorker($resize);
        $this->assertSame(ProvisioningJobStatus::Queued, $resize->refresh()->status);
        $this->assertSame(ResizeVpsHandler::IN_PROGRESS, $resize->result['error']['code'] ?? null);

        $this->theTasksFinish();
        $this->runWorker($resize);

        $resize->refresh();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->status, (string) $resize->last_error);
        $this->assertSame(400, $this->fleetDisk($machine), 'The disk was grown more than once.');
        $this->assertSame([4, 8192, 400], $this->rowShape($machine), 'The row does not record the disk the task gave the machine.');
        $this->assertSame([4, 8192, 400], $this->committed());
        $this->assertSame(400, (int) $this->node->refresh()->allocated_storage_gib, 'The node is committed for less disk than the machine has.');
        $this->assertArrayNotHasKey(ResizeVpsHandler::TASK_IN_FLIGHT, $resize->result ?? []);
    }

    #[Test]
    public function a_task_that_outlasts_the_attempts_stops_in_review_and_the_operators_retry_finishes_it(): void
    {
        $machine = $this->aBuiltMachine();
        $resize = $this->resizeJob($machine, vcpu: 2, memoryMib: 4096, diskGib: 160);

        foreach (range(1, $resize->max_attempts) as $attempt) {
            $this->runWorker($resize);
        }

        $resize->refresh();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $resize->status);
        $this->assertSame(ResizeVpsHandler::IN_PROGRESS, $resize->result['error']['code'] ?? null);
        $this->assertNull($resize->remote_job_id, 'The task was recorded where the retry refuses a job.');
        $this->assertSame(40, $this->fleetDisk($machine));
        $this->assertSame(40, $machine->refresh()->disk_gib);

        $this->theTasksFinish();
        $this->retryAsOperator($resize)->assertOk();
        DB::table('provisioning_jobs')->where('id', $resize->id)->update(['next_attempt_at' => null]);
        $this->runWorker($resize);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->refresh()->status, (string) $resize->last_error);
        $this->assertSame(160, $this->fleetDisk($machine), 'The retry grew the disk again.');
        $this->assertSame(160, $machine->refresh()->disk_gib);
        $this->assertSame(160, NodeCapacityReservation::query()->whereNull('released_at')->sole()->disk_gib);
    }

    #[Test]
    public function a_change_with_no_disk_growth_is_finished_when_it_is_answered(): void
    {
        $machine = $this->aBuiltMachine();
        $resize = $this->resizeJob($machine, vcpu: 4, memoryMib: 8192, diskGib: 40);

        $this->runWorker($resize);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->refresh()->status, (string) $resize->last_error);
        $this->assertSame([4, 8192, 40], $this->rowShape($machine));
    }

    /**
     * Time passing, for the simulator: a second instance, with no delay, over
     * the same fleet - for which every task has finished, as getTask() reads
     * a UPID.
     */
    private function theTasksFinish(): void
    {
        config()->set('compute.fake.task_delay_seconds', 0);
        $this->hypervisor = new AnswerLosingComputeProvider(new FakeComputeProvider);
        $this->hypervisor->loseTheAnswerToCreates = false;
        app(ComputeProviderFactory::class)->swap($this->cluster, $this->hypervisor);
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

    /**
     * @return array{int, int, int}
     */
    private function rowShape(VirtualMachine $machine): array
    {
        $machine->refresh();

        return [$machine->vcpu, $machine->memory_mib, $machine->disk_gib];
    }

    private function fleetDisk(VirtualMachine $machine): ?int
    {
        return $this->hypervisor->fleet->getVm('pve-01', (string) $machine->provider_id)?->diskGib;
    }

    /**
     * @return array{int, int, int}
     */
    private function committed(): array
    {
        $live = NodeCapacityReservation::query()->whereNull('released_at')->sole();

        return [(int) $live->vcpu, (int) $live->memory_mib, (int) $live->disk_gib];
    }
}
