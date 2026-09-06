<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Provisioning\Application\Actions\DetectStaleJobs;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Events\ProvisioningJobNeedsReview;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * The sweeper that finds jobs whose worker never came back.
 *
 * Without it, a worker killed mid-call leaves a job marked running for ever:
 * no other worker may claim it, so it is an invisible failure holding
 * reservations nobody will ever hand back.
 */
final class DetectStaleJobsTest extends ProvisioningTestCase
{
    use RefreshDatabase;

    private DetectStaleJobs $detect;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detect = app(DetectStaleJobs::class);
    }

    #[Test]
    public function a_job_running_past_its_timeout_is_moved_to_review(): void
    {
        Event::fake([ProvisioningJobNeedsReview::class]);

        $job = ProvisioningJob::factory()->running(startedSecondsAgo: 1_000)->create([
            'timeout_seconds' => 900,
        ]);

        $this->assertSame(1, $this->detect->execute());

        $job = $job->fresh();
        $this->assertNotNull($job);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(FailureClass::Timeout, $job->failure_class);
        $this->assertNotNull($job->finished_at);
        $this->assertStringContainsString('may exist at the provider', (string) $job->last_error);

        Event::assertDispatched(ProvisioningJobNeedsReview::class);
    }

    #[Test]
    public function a_stale_job_is_quarantined_and_never_requeued(): void
    {
        $job = ProvisioningJob::factory()->running(startedSecondsAgo: 1_000)->create([
            'timeout_seconds' => 900,
        ]);

        $this->detect->execute();

        /*
         * The platform stopped waiting; what the provider did is unknown. That
         * is the same fact a timeout observed in-process carries, so it gets
         * the same two answers: quarantine, and a person. Requeueing here
         * would be the worst possible move — the original worker may still be
         * alive and mid-build.
         */
        $this->assertSame(['quarantine'], $this->releaser->actions());
        $this->assertTrue($this->releaser->quarantined($job));
        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    public function a_job_still_inside_its_timeout_is_left_alone(): void
    {
        $job = ProvisioningJob::factory()->running(startedSecondsAgo: 60)->create([
            'timeout_seconds' => 900,
        ]);

        $this->assertSame(0, $this->detect->execute());

        // A build that is simply taking a while is not a failure, and dragging
        // it into review would quarantine addresses that are still in use.
        $this->assertSame(ProvisioningJobStatus::Running, $job->fresh()?->status);
        $this->assertSame([], $this->releaser->actions());
    }

    #[Test]
    public function each_kind_is_held_to_its_own_clock(): void
    {
        $powerOn = ProvisioningJob::factory()->running(startedSecondsAgo: 400)->create([
            'timeout_seconds' => 300,
        ]);
        $dedicated = ProvisioningJob::factory()->running(startedSecondsAgo: 400)->create([
            'timeout_seconds' => 5_400,
        ]);

        $this->assertSame(1, $this->detect->execute());

        // A dedicated server install and a reboot cannot share one deadline.
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $powerOn->fresh()?->status);
        $this->assertSame(ProvisioningJobStatus::Running, $dedicated->fresh()?->status);
    }

    #[Test]
    public function a_job_that_finished_between_the_sweep_and_the_write_is_not_disturbed(): void
    {
        $job = ProvisioningJob::factory()->running(startedSecondsAgo: 1_000)->create([
            'timeout_seconds' => 900,
        ]);

        // The worker settles the job in the window between the sweeper's read
        // and its write. Re-reading under the lock is what stops a job that
        // succeeded one second ago being dragged into review and having its
        // live addresses quarantined.
        ProvisioningJob::query()->whereKey($job->getKey())->update([
            'status' => ProvisioningJobStatus::Succeeded->value,
            'finished_at' => now(),
        ]);

        $this->assertSame(0, $this->detect->execute());
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->fresh()?->status);
        $this->assertSame([], $this->releaser->actions());
    }

    #[Test]
    public function a_quarantine_that_fails_is_recorded_and_the_sweep_carries_on(): void
    {
        $this->app->instance(ResourceReservationReleaser::class, new class implements ResourceReservationReleaser
        {
            public function release(ProvisioningJob $job): int
            {
                throw new RuntimeException('IPAM is unreachable');
            }

            public function quarantine(ProvisioningJob $job, string $reason): int
            {
                throw new RuntimeException('IPAM is unreachable');
            }
        });

        $first = ProvisioningJob::factory()->running(startedSecondsAgo: 1_000)->create(['timeout_seconds' => 900]);
        $second = ProvisioningJob::factory()->running(startedSecondsAgo: 900)->create(['timeout_seconds' => 300]);

        /*
         * The sweeper is the last line of defence for a worker that never came
         * back. If one unreachable IPAM aborts the whole run, the jobs behind
         * the failing one are not swept either — and the failing one has
         * already been committed to review, so no later sweep will look at it
         * again and nothing anywhere says its addresses are still out of the
         * pool.
         */
        $this->assertSame(2, app(DetectStaleJobs::class)->execute());

        foreach ([$first, $second] as $job) {
            $job = $job->fresh();
            $this->assertNotNull($job);
            $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
            $this->assertStringContainsString('compensation also failed', (string) $job->last_error);
        }
    }

    #[Test]
    public function a_sweep_is_bounded_so_a_backlog_does_not_become_one_transaction(): void
    {
        ProvisioningJob::factory()->count(3)->running(startedSecondsAgo: 1_000)->create([
            'timeout_seconds' => 900,
        ]);

        $this->assertSame(2, $this->detect->execute(limit: 2));
        $this->assertSame(1, $this->detect->execute(limit: 2));
        $this->assertSame(0, $this->detect->execute(limit: 2));
    }
}
