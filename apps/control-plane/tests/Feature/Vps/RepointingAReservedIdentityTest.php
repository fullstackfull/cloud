<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Provisioning\Application\Actions\DetectStaleJobs;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * The route out of a provider identity somebody else's machine holds — and
 * every case in which it must not be taken.
 *
 * A create's identity is derived from its order's key, and a derived id lands
 * on an existing machine often enough at scale to be certain. Without a way
 * to move the job, `vps.create_identity_taken` would be a dead end the retry
 * walks into for ever. With one taken at the wrong moment, it is the second
 * machine F-15 exists to prevent, arrived at by a different door: a job moved
 * off an identity under which its own machine sits builds again elsewhere.
 *
 * So most of this file is refusals, one per code, each driven through the
 * operator's own route — and the door the stale sweeper used to hold open,
 * reproduced end to end with nothing edited but the hypervisor.
 */
final class RepointingAReservedIdentityTest extends TestCase
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
    public function a_job_whose_identity_a_stranger_holds_is_moved_and_then_builds_under_the_new_one(): void
    {
        $job = $this->createJob();
        $taken = $this->derivedIdOf($job);
        $this->aStrangerAt($taken);

        $this->runWorker($job);
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->refresh()->result['error']['code'] ?? null);

        $response = $this->repointAsOperator($job)->assertOk();
        $moved = (string) $response->json('data.reserved_provider_id');

        $this->assertNotSame($taken, $moved);
        $this->assertSame($taken, $response->json('data.previous_provider_id'));

        $job->refresh();
        $this->assertSame($moved, $job->reserved_provider_id);
        $this->assertSame($this->cluster->id, $job->reserved_cluster_id);
        $this->assertNull($job->reserved_provider_nodes);
        $this->assertNull($job->reserved_provider_hostnames);
        $this->assertSame($taken, $job->result['repoints'][0]['from'] ?? null);
        $this->assertSame(['web-01'], $job->result['repoints'][0]['hostnames'] ?? null);

        $entry = AuditEntry::query()->where('action', AuditAction::ProvisioningIdentityRepointed)->sole();
        $this->assertSame($taken, $entry->context['from'] ?? null);
        $this->assertSame($moved, $entry->context['to'] ?? null);

        // Not requeued by the repoint: the operator retries, and the retry
        // looks under the new identity before it builds.
        $this->assertSame(ProvisioningJobStatus::Failed, $job->status);

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status, (string) $job->last_error);
        $this->assertCount(1, $this->machinesNamed('web-01'));
        $this->assertSame($moved, $this->machinesNamed('web-01')[0]->providerId);
        $this->assertCount(1, $this->machinesNamed('someone-elses-box'), 'the stranger was touched');
    }

    #[Test]
    public function a_job_that_has_not_stopped_is_not_repointed(): void
    {
        $job = $this->aJobWhoseIdentityIsTaken();
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['status' => ProvisioningJobStatus::Running->value]);

        $this->assertRefused($job, 'provisioning.repoint_job_not_stopped');
    }

    #[Test]
    public function a_job_that_holds_no_identity_is_not_repointed(): void
    {
        $job = $this->createJob(attributes: ['status' => ProvisioningJobStatus::Failed]);

        $this->assertRefused($job, 'provisioning.repoint_nothing_reserved');
    }

    #[Test]
    public function a_job_that_found_its_own_build_is_adopted_not_repointed(): void
    {
        $job = $this->createJob();
        $this->hypervisor->loseTheAnswerToCreates = true;

        $this->runWorker($job);
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->refresh()->result['error']['code'] ?? null);

        $this->assertRefused($job, 'provisioning.repoint_would_duplicate');
    }

    #[Test]
    public function a_job_whose_last_finding_is_not_a_taken_identity_is_not_repointed(): void
    {
        $job = $this->createJob(
            ['hostname' => 'web-01-'.FakeComputeProvider::PROVIDER_FAILURE_MARKER],
            ['max_attempts' => 1],
        );

        $this->runWorker($job);

        $this->assertNotNull($job->refresh()->reserved_provider_id);
        $this->assertRefused($job, 'provisioning.repoint_no_identity_finding');
    }

    #[Test]
    public function a_finding_from_an_earlier_attempt_licenses_nothing(): void
    {
        /*
         * The time dimension on its own, with the sweep's half out of the
         * picture: a finding that the identity is taken, still in place, but
         * written by an attempt before the job's last one. Whatever the last
         * attempt did — it may have built under this very identity and died —
         * the old finding says nothing about it.
         */
        $job = $this->aJobWhoseIdentityIsTaken();
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['attempts' => $job->attempts + 1]);

        $this->assertRefused($job, 'provisioning.repoint_finding_is_stale');
    }

    #[Test]
    public function a_finding_about_an_identity_the_job_no_longer_holds_licenses_nothing(): void
    {
        $job = $this->aJobWhoseIdentityIsTaken();

        $this->repointAsOperator($job)->assertOk();

        // The same finding a second time: it is about the identity the job
        // has just been moved off, not the one it holds.
        $this->assertRefused($job, 'provisioning.repoint_finding_is_stale');
    }

    #[Test]
    public function a_machine_whose_ownership_was_not_established_is_not_repointed_around(): void
    {
        $job = $this->createJob(['hostname' => 'web-01-'.FakeComputeProvider::UNNAMED_MARKER]);
        $this->hypervisor->loseTheAnswerToCreates = true;

        $this->runWorker($job);
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertSame(CreateVpsHandler::REASON_UNNAMED, $job->refresh()->result['error']['reason'] ?? null);

        $this->assertRefused($job, 'provisioning.repoint_ownership_not_established');
    }

    #[Test]
    public function the_stale_sweep_reaches_a_job_the_engine_claimed_in_this_very_test(): void
    {
        /*
         * `scopeStale` used to compare against Postgres now(), which is frozen
         * at the start of the transaction every test runs in, so it could
         * never select a job whose started_at the test itself caused to be
         * written — and every sweep test wound started_at back by hand. The
         * door below runs through the sweep; this is what makes it reachable.
         */
        $job = $this->createJob(attributes: ['timeout_seconds' => 1]);

        $this->runWorkerThatDiesAfterBuilding($job);
        $this->assertSame(ProvisioningJobStatus::Running, $job->refresh()->status);

        usleep(1_200_000);

        $this->assertSame(1, app(DetectStaleJobs::class)->execute());
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
    }

    #[Test]
    public function the_stale_sweep_writes_its_own_finding_over_an_earlier_attempts(): void
    {
        /*
         * The sweep's half on its own: whatever the previous attempt found is
         * replaced by what the sweep knows — that no attempt answered — and
         * stamped with the attempt it is about.
         */
        $job = $this->aJobWhoseIdentityIsTaken();
        $this->hypervisor->fleet->destroyVm('pve-01', (string) $job->reserved_provider_id);
        $this->retryAsOperator($job)->assertOk();
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['timeout_seconds' => 1]);

        $this->runWorkerThatDiesAfterBuilding($job->refresh());
        usleep(1_200_000);
        app(DetectStaleJobs::class)->execute();

        $job->refresh();
        $this->assertSame(DetectStaleJobs::ERROR_CODE, $job->result['error']['code'] ?? null);
        $this->assertSame($job->attempts, $job->result['error']['attempt'] ?? null);
        $this->assertNull($job->result['error']['reason'] ?? null);
    }

    #[Test]
    public function a_build_whose_worker_died_is_not_repointed_around_after_the_sweep(): void
    {
        /*
         * The door, end to end, with nothing edited but the hypervisor.
         *
         *  1. The job's identity is taken by a stranger: the finding says so,
         *     named otherwise — a genuine licence to repoint, at that moment.
         *  2. The operator clears the stranger at the hypervisor instead, and
         *     retries.
         *  3. The retry builds this job's machine under the SAME identity, and
         *     its worker dies before writing anything down.
         *  4. The stale sweep moves the job to review on its deadline alone.
         *  5. The old finding still says "taken, named otherwise, about the
         *     identity the job holds" — and a repoint it licensed would move
         *     the job off the identity its own machine now sits under, and the
         *     next retry would build a second one.
         *
         * Closed twice over: the sweep writes its own finding, and the repoint
         * refuses a finding from any attempt but the last. Either alone closes
         * it; the two tests above pin each alone.
         */
        $job = $this->aJobWhoseIdentityIsTaken();
        $identity = (string) $job->reserved_provider_id;

        $this->hypervisor->fleet->destroyVm('pve-01', $identity);
        $this->retryAsOperator($job)->assertOk();
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['timeout_seconds' => 1]);

        $this->runWorkerThatDiesAfterBuilding($job->refresh());
        $this->assertCount(1, $this->machinesNamed('web-01'));

        usleep(1_200_000);
        $this->assertSame(1, app(DetectStaleJobs::class)->execute());
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);

        $this->repointAsOperator($job)->assertStatus(409);
        $this->assertSame($identity, $job->refresh()->reserved_provider_id);

        // What the operator can do instead is retry, and the retry finds the
        // build the dead worker left.
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['timeout_seconds' => 900]);
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null);
        $this->assertSame($identity, $job->result['provider_reference'] ?? null);
        $this->assertCount(1, $this->machinesNamed('web-01'), 'two machines for one job');
    }

    // ---------------------------------------------------------------------

    private function aJobWhoseIdentityIsTaken(): ProvisioningJob
    {
        $job = $this->createJob();
        $this->aStrangerAt($this->derivedIdOf($job));

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);

        return $job;
    }

    private function assertRefused(ProvisioningJob $job, string $code): void
    {
        $before = $job->refresh()->reserved_provider_id;
        $audited = AuditEntry::query()->where('action', AuditAction::ProvisioningIdentityRepointed)->count();

        $this->repointAsOperator($job)
            ->assertStatus(409)
            ->assertJsonPath('error.code', $code);

        $this->assertSame($before, $job->refresh()->reserved_provider_id, 'a refused repoint moved the identity');
        $this->assertSame($audited, AuditEntry::query()->where('action', AuditAction::ProvisioningIdentityRepointed)->count());
    }
}
