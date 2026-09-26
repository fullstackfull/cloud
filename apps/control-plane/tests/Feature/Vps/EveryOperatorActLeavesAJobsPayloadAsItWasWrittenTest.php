<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\PollProviderTasks;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Provisioning\Application\Actions\DetectStaleJobs;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * The behavioural half of the bound F-15's ownership rule rests on.
 *
 * A create claims a machine it finds under its reserved identity as its own
 * when the machine's name is one a create under it was sent with — and the
 * names it sent come from its payload, recorded append-only in
 * `reserved_provider_hostnames` immediately before each create is sent. That
 * is safe while the payload is written once. So every act the platform
 * offers an operator over a VPS create's job — retry, adopt and repoint, the
 * refused ones included — and both sweepers that act on one without an
 * operator, the stale sweep and the task poller, are driven here, and after
 * each the payload must come out byte-for-byte as it was written and the list
 * of names must hold exactly the one name the payload gives, or none where
 * no create has been sent.
 *
 * That includes the one act that writes onto a job something the operator
 * typed: the correction of the domain a stopped hosting build will serve
 * (F-04). It is driven twice — on a VPS create, which it refuses, and on the
 * hosting build it exists for, where it records the name in a column of its
 * own and the build is then retried and built under it — and the payload
 * comes out of both as it went in.
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
        // It found the stranger before sending anything, so no name has been
        // recorded as sent — and none may have been.
        $this->assertUntouched($job, $written, 'the attempt that found a stranger', sent: false);

        $this->nameHostingDomainAsOperator($job, 'shop.example.test')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'hosting.job_not_a_hosting_build');
        $this->assertUntouched($job, $written, 'the hosting-domain correction, refused for a VPS create', sent: false);
        $this->assertNull($job->refresh()->operator_named_domain);

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
    public function a_hosting_build_named_retried_and_built_leaves_the_payload_untouched(): void
    {
        HostingNode::factory()->create([
            'panel' => HostingPanel::Fake,
            'max_accounts' => 50,
            'account_count' => 0,
            'disk_total_mib' => 2_097_152,
            'disk_used_mib' => 209_715,
        ]);

        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::CreateHostingAccount)->create([
            'customer_id' => $this->customer->id,
            'status' => ProvisioningJobStatus::Queued,
            // No domain: every hosting order placed before checkout asked for one.
            'payload' => ['hosting_package_id' => (string) HostingPackage::factory()->create()->getKey()],
        ]);
        $written = $this->payloadOf($job);

        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::Failed, $job->refresh()->status);
        $this->assertSame('hosting.domain_missing', $job->result['error']['code'] ?? null);
        $this->assertSame($written, $this->payloadOf($job), 'The payload changed during the attempt that found no domain.');

        $this->nameHostingDomainAsOperator($job, 'Shop.Example.Test')->assertOk();
        $this->assertSame($written, $this->payloadOf($job), 'The payload changed during the hosting-domain correction: it has a second writer.');
        $this->assertSame('shop.example.test', $job->refresh()->operator_named_domain);

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status);
        $this->assertSame('shop.example.test', HostingAccount::query()->where('customer_id', $this->customer->id)->sole()->primary_domain);
        $this->assertSame($written, $this->payloadOf($job), 'The payload changed during the retry that built under the named domain.');
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

    /**
     * The payload byte for byte as written, and the names a create under the
     * identity was sent with exactly the one it gives — or, where no create
     * has been sent, none at all.
     */
    private function assertUntouched(ProvisioningJob $job, string $written, string $after, string $hostname = 'web-01', bool $sent = true): void
    {
        $this->assertSame($written, $this->payloadOf($job), sprintf('The payload changed during %s: it has a second writer.', $after));

        $this->assertSame(
            $sent ? [$hostname] : null,
            $job->refresh()->reserved_provider_hostnames,
            $sent
                ? sprintf('After %s the job has called its machine by more than one name, so the ownership rule claims more than it built.', $after)
                : sprintf('After %s the job records a name as sent when it sent nothing, so the ownership rule can claim what it never built.', $after),
        );
    }
}
