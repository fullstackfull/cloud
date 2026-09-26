<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\PollProviderTasks;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Provisioning\Application\Actions\DetectStaleJobs;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * The behavioural half of the bound F-15's ownership rule rests on.
 *
 * A create claims a machine it finds under its reserved identity as its own
 * when the machine's name is one the job sent — and the names it sent come
 * from its payload, recorded append-only in `reserved_provider_hostnames`.
 * That is safe while the payload is written once. So every act the platform
 * offers an operator over a VPS create's job — retry, adopt and repoint, the
 * refused ones included — and both sweepers that act on one without an
 * operator, the stale sweep and the task poller, are driven here, and after
 * each the payload must come out byte-for-byte as it was written and the list
 * of names must still hold exactly one.
 *
 * It is the effect that is asserted, not a count of writers: a second writer
 * planted on any of these paths — a hostname "normalised while we are
 * requeuing anyway", a retry stamped into the payload for the screen — shows
 * up here as a payload that changed or a list that grew, whatever shape the
 * write took. What it cannot see is a writer on a path it does not drive:
 * the engine's handling of every other kind of job, the operator's verdict on
 * a rebuild (which settles a reinstall's job, never a create's), and any code
 * nothing here calls. That is the static census's half, in the Provisioning
 * band.
 */
final class EveryOperatorActLeavesAJobsPayloadAsItWasWrittenTest extends TestCase
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
    public function a_lost_answer_retried_and_adopted_leaves_the_payload_untouched(): void
    {
        $job = $this->createJob();
        $written = $this->payloadOf($job);

        $this->runWorker($job);
        $this->assertUntouched($job, $written, 'the attempt that lost its answer');

        $this->retryAsOperator($job)->assertOk();
        $this->assertUntouched($job, $written, 'the operator\'s retry');

        $this->runWorker($job);
        $this->assertUntouched($job, $written, 'the retried attempt');

        $this->adoptAsOperator($job, (string) $job->refresh()->reserved_provider_id)->assertOk();
        $this->assertUntouched($job, $written, 'the adoption');
    }

    #[Test]
    public function a_taken_identity_repointed_and_retried_leaves_the_payload_untouched(): void
    {
        $this->hypervisor->loseTheAnswerToCreates = false;

        $job = $this->createJob();
        $written = $this->payloadOf($job);
        $this->aStrangerAt($this->derivedIdOf($job));

        $this->runWorker($job);
        $this->assertUntouched($job, $written, 'the attempt that found a stranger');

        $this->repointAsOperator($job)->assertOk();
        $this->assertSame(null, $job->refresh()->reserved_provider_hostnames, 'a repoint starts the new identity\'s names empty');
        $this->assertSame($written, $this->payloadOf($job), 'the repoint changed the payload');

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status);
        $this->assertUntouched($job, $written, 'the retry under the new identity');
    }

    #[Test]
    public function a_stale_sweep_leaves_the_payload_untouched(): void
    {
        $this->hypervisor->loseTheAnswerToCreates = false;

        $job = $this->createJob(attributes: ['timeout_seconds' => 1]);
        $written = $this->payloadOf($job);

        $this->runWorkerThatDiesAfterBuilding($job);
        usleep(1_200_000);
        $this->assertSame(1, app(DetectStaleJobs::class)->execute());

        $this->assertUntouched($job, $written, 'the stale sweep');

        DB::table('provisioning_jobs')->where('id', $job->id)->update(['timeout_seconds' => 900]);
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertUntouched($job, $written, 'the retry after the sweep');
    }

    #[Test]
    public function a_failed_task_sent_back_to_review_leaves_the_payload_untouched(): void
    {
        /*
         * The other sweeper, which acts on a create's job after it has
         * succeeded: the hypervisor says the build's task failed, and the
         * poller moves the job back to review with a finding of its own —
         * the door a "stamp it for the screen" writer would most naturally
         * be added to. Then every act an operator is offered from there.
         */
        $this->hypervisor->loseTheAnswerToCreates = false;

        $hostname = 'web-01-'.FakeComputeProvider::TASK_FAILURE_MARKER;
        $job = $this->createJob(['hostname' => $hostname]);
        $written = $this->payloadOf($job);

        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status, (string) $job->last_error);
        $this->assertUntouched($job, $written, 'the build', $hostname);

        $this->assertSame(['polled' => 1, 'confirmed' => 0, 'review' => 1], app(PollProviderTasks::class)->execute());
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
        $this->assertUntouched($job, $written, 'the task poller', $hostname);

        $this->retryAsOperator($job)->assertStatus(409);
        $this->assertUntouched($job, $written, 'a refused retry', $hostname);

        $this->repointAsOperator($job)->assertStatus(409);
        $this->assertUntouched($job, $written, 'a refused repoint', $hostname);

        $this->adoptAsOperator($job, (string) $job->reserved_provider_id)->assertOk();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status);
        $this->assertUntouched($job, $written, 'the adoption', $hostname);
    }

    /**
     * The payload as the database holds it, not as a model decodes it: a
     * writer that reorders keys or re-encodes a value has written.
     */
    private function payloadOf(ProvisioningJob $job): string
    {
        return (string) DB::table('provisioning_jobs')->where('id', $job->id)->value('payload');
    }

    private function assertUntouched(ProvisioningJob $job, string $written, string $after, string $hostname = 'web-01'): void
    {
        $this->assertSame($written, $this->payloadOf($job), sprintf('The payload changed during %s: it has a second writer.', $after));

        $this->assertSame(
            [$hostname],
            $job->refresh()->reserved_provider_hostnames,
            sprintf('After %s the job has called its machine by more than one name, so the ownership rule claims more than it built.', $after),
        );
    }
}
