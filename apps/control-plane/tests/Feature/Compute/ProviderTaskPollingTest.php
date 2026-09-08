<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Compute\Application\Actions\PollProviderTasks;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Asking the hypervisor what became of the tasks the platform handed off.
 *
 * The gap being closed: on Proxmox a create answers in milliseconds with a
 * UPID and builds the machine minutes later. The handler writes its rows, the
 * engine marks the job succeeded, and the customer is told their server is
 * ready — none of which is evidence that the build finished. A clone that ran
 * out of space leaves the platform holding a machine row, an assigned address
 * and a billed service for something that does not exist.
 */
final class ProviderTaskPollingTest extends TestCase
{
    use RefreshDatabase;

    private ComputeCluster $cluster;

    private ComputeNode $node;

    protected function setUp(): void
    {
        parent::setUp();

        // One factory for the test and the action, so both talk to the same
        // in-memory hypervisor.
        $this->app->singleton(ComputeProviderFactory::class);

        $this->cluster = ComputeCluster::factory()->create(['status' => 'active']);
        $this->node = ComputeNode::factory()->withCapacity(16, 32_768, 1_000)->create([
            'cluster_id' => $this->cluster->id,
            'status' => NodeStatus::Active,
        ]);
    }

    #[Test]
    public function a_task_the_hypervisor_finished_is_confirmed_and_never_asked_about_again(): void
    {
        $job = $this->buildOf('web-kw-01');

        $outcome = app(PollProviderTasks::class)->execute();

        $this->assertSame(1, $outcome['polled']);
        $this->assertSame(1, $outcome['confirmed']);

        $this->assertSame('succeeded', $job->fresh()?->remote_task_state);

        // Asked once. The whole point of stamping the outcome is that a
        // confirmed task stops costing an API call every five minutes.
        $this->assertSame(0, app(PollProviderTasks::class)->execute()['polled']);
    }

    #[Test]
    public function a_task_that_failed_after_the_job_reported_success_goes_in_front_of_a_person(): void
    {
        Event::fake([ProvisioningJobNeedsReview::class]);

        // The fake fails the task of any machine whose hostname carries the
        // marker — the shape a clone that ran out of space really takes.
        $job = $this->buildOf('task-fail-kw-01');

        $outcome = app(PollProviderTasks::class)->execute();

        $this->assertSame(1, $outcome['review']);

        $job->refresh();

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(FailureClass::Permanent, $job->failure_class);
        $this->assertStringContainsString('ended in failure', (string) $job->last_error);

        Event::assertDispatched(ProvisioningJobNeedsReview::class);

        /*
         * And recorded as drift, because "the platform believes in a machine
         * the provider does not" is exactly what that ledger is for — and
         * because the service is being billed for in the meantime.
         */
        $this->assertTrue(
            ResourceDrift::query()
                ->where('resource_type', 'virtual_machine')
                ->where('kind', DriftKind::MissingAtProvider->value)
                ->exists(),
        );
    }

    #[Test]
    public function nothing_is_destroyed_or_released_on_the_strength_of_a_failed_task(): void
    {
        $job = $this->buildOf('task-fail-kw-01');

        app(PollProviderTasks::class)->execute();

        /*
         * The machine row survives. A build whose task failed may have left a
         * disk, a machine or nothing at all behind, and cleaning up on that
         * guess is how the next customer gets an address that still answers
         * for somebody else.
         */
        $this->assertTrue(
            VirtualMachine::query()->where('service_id', $job->service_id)->exists(),
        );
    }

    #[Test]
    public function a_task_still_running_is_left_alone_until_the_platform_stops_waiting(): void
    {
        // Long enough that the fake reports every task as still running.
        config()->set('compute.fake.task_delay_seconds', 3600);

        $job = $this->buildOf('web-kw-01');

        $outcome = app(PollProviderTasks::class)->execute();

        $this->assertSame(0, $outcome['review']);
        $this->assertNull($job->fresh()?->remote_task_state);
        $this->assertSame(1, $job->fresh()?->remote_task_poll_count);
    }

    #[Test]
    public function a_task_that_never_finishes_becomes_somebody_elses_decision(): void
    {
        Event::fake([ProvisioningJobNeedsReview::class]);

        config()->set('compute.fake.task_delay_seconds', 7200);
        config()->set('compute.tasks.give_up_after_minutes', 60);

        $job = $this->buildOf('web-kw-01');

        // The job finished being *run* two hours ago; its task has had long
        // enough.
        $job->forceFill(['finished_at' => CarbonImmutable::now()->subHours(2)])->save();

        $outcome = app(PollProviderTasks::class)->execute();

        $this->assertSame(1, $outcome['review']);

        /*
         * The Timeout Rule. Not "failed" — the task may well still be running
         * — and not "succeeded" either. What has failed is the platform's
         * ability to keep track, and only a person can settle that.
         */
        $this->assertSame(FailureClass::Timeout, $job->fresh()?->failure_class);
        $this->assertSame('unknown', $job->fresh()?->remote_task_state);

        Event::assertDispatched(ProvisioningJobNeedsReview::class);
    }

    #[Test]
    public function the_backoff_widens_so_a_fleet_of_slow_builds_is_not_a_fleet_of_calls(): void
    {
        config()->set('compute.fake.task_delay_seconds', 7200);
        config()->set('compute.tasks.poll_base_minutes', 1);

        $job = $this->buildOf('web-kw-01');

        app(PollProviderTasks::class)->execute();
        $this->assertSame(1, $job->fresh()?->remote_task_poll_count);

        // A minute has not passed, so it is not due.
        $this->assertSame(0, app(PollProviderTasks::class)->execute()['polled']);

        // Four polls in, the wait is sixteen minutes rather than one.
        $job->forceFill([
            'remote_task_poll_count' => 4,
            'remote_task_polled_at' => CarbonImmutable::now()->subMinutes(10),
        ])->save();

        $this->assertSame(0, app(PollProviderTasks::class)->execute()['polled']);

        $job->forceFill(['remote_task_polled_at' => CarbonImmutable::now()->subMinutes(20)])->save();

        $this->assertSame(1, app(PollProviderTasks::class)->execute()['polled']);
    }

    #[Test]
    public function a_job_whose_machine_is_gone_is_closed_rather_than_reviewed(): void
    {
        $job = $this->buildOf('web-kw-01');

        VirtualMachine::query()->where('service_id', $job->service_id)->delete();

        $outcome = app(PollProviderTasks::class)->execute();

        // A terminated service is not a broken build. Marking one for review
        // would put every ended VPS in an operator's queue.
        $this->assertSame(0, $outcome['review']);
        $this->assertSame('unknown', $job->fresh()?->remote_task_state);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);
    }

    #[Test]
    public function the_command_reports_what_it_did(): void
    {
        $this->buildOf('web-kw-01');

        $this->artisan('compute:poll-tasks')
            ->expectsOutputToContain('1 asked about')
            ->assertSuccessful();
    }

    /**
     * A build that the platform has already called a success: the machine row
     * written, the job marked succeeded, the handle and its node recorded.
     */
    private function buildOf(string $hostname): ProvisioningJob
    {
        $service = Service::factory()->create(['kind' => 'vps']);

        $operation = app(ComputeProviderFactory::class)->for($this->cluster)->createVirtualMachine(
            new CreateVmRequest(
                nodeName: $this->node->provider_name,
                vmId: random_int(10_000, 99_999),
                hostname: $hostname,
                vcpu: 2,
                memoryMib: 4096,
                diskGib: 40,
                storageName: 'local-lvm',
                osFamily: OsFamily::Debian,
            ),
        );

        VirtualMachine::factory()->onNode($this->node)->create([
            'service_id' => $service->getKey(),
            'provider_id' => $operation->providerId,
            'hostname' => $hostname,
        ]);

        $job = ProvisioningJob::factory()->create([
            'service_id' => $service->getKey(),
            'kind' => ProvisioningJobKind::CreateVps,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Succeeded,
            'finished_at' => CarbonImmutable::now(),
        ]);

        $job->recordRemoteJobId($operation->taskId, $this->node->provider_name);

        return $job->refresh();
    }
}
