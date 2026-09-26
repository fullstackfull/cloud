<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\PollProviderTasks;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * The finding on the review list is the last attempt's, and never one an
 * earlier attempt left behind.
 *
 * The review list publishes `error_code` and `error_reason` from the job's
 * finding, `result.error`, and the runbook's rows are keyed on the pair. A
 * finding left over from an earlier attempt sends the operator to the wrong
 * row. The case that was found: a build whose identity a stranger held was
 * repointed and retried, the retry built and succeeded, and its hypervisor
 * task then failed — so the task poller moved the job back to review, and the
 * list said "somebody else's machine holds the id; nothing of this build's
 * exists; repoint and retry" about a job whose own machine was sitting at the
 * id it named.
 *
 * Four things keep it from happening, and each is pinned here on its own:
 *
 *  - a successful attempt removes the finding (the engine, `mergedResult()`);
 *  - the task poller writes its own finding when it moves a job to review,
 *    as the stale sweeper does;
 *  - an adoption, which is recorded as an attempt, moves the finding it
 *    resolved into its own record;
 *  - and the list itself publishes a finding only when it is stamped with the
 *    job's last attempt, as the repoint already required before it acts on
 *    one.
 */
final class TheReviewListShowsTheLastAttemptsFindingTest extends TestCase
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
    public function a_build_whose_task_failed_after_a_repoint_is_not_listed_under_the_strangers_finding(): void
    {
        /*
         * The case as it was found, end to end, with nothing edited but the
         * hypervisor.
         */
        $job = $this->createJob(['hostname' => 'web-01-'.FakeComputeProvider::TASK_FAILURE_MARKER]);
        $this->aStrangerAt($this->derivedIdOf($job));

        $this->runWorker($job);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->refresh()->result['error']['reason'] ?? null);

        $this->repointAsOperator($job)->assertOk();
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status, (string) $job->last_error);
        $built = (string) $job->reserved_provider_id;

        $this->assertSame(['polled' => 1, 'confirmed' => 0, 'review' => 1], app(PollProviderTasks::class)->execute());
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);

        $row = $this->reviewRowFor($job);

        $this->assertNotSame(
            CreateVpsHandler::IDENTITY_TAKEN,
            $row['error_code'] ?? null,
            'the review list says somebody else holds the id of a job whose own machine is at it',
        );
        $this->assertSame(PollProviderTasks::TASK_FAILED, $row['error_code'] ?? null);
        $this->assertNull($row['error_reason'] ?? null);
        $this->assertSame($built, $row['reserved_provider_id'] ?? null);
        $this->assertSame($built, $row['provider_reference'] ?? null);
        $this->assertCount(1, $this->machinesNamed('web-01-'.FakeComputeProvider::TASK_FAILURE_MARKER));
    }

    #[Test]
    public function a_successful_attempt_leaves_no_earlier_finding_behind(): void
    {
        /*
         * The engine's half on its own: one attempt that could not ask the
         * hypervisor, which the engine retries by itself, and one that
         * builds. What the first found is not what the job's last attempt
         * found.
         */
        $job = $this->createJob();
        $this->hypervisor->failReadsWith = ComputeProviderException::requestFailed('fake', 'get_vm', [
            'provider_message' => 'the node did not answer',
        ]);

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status);
        $this->assertSame(CreateVpsHandler::IDENTITY_UNVERIFIABLE, $job->result['error']['code'] ?? null);

        $this->hypervisor->failReadsWith = null;
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status, (string) $job->last_error);
        $this->assertSame(2, $job->attempts);
        $this->assertArrayNotHasKey('error', $job->result ?? [], 'a succeeded job still carries an earlier attempt\'s finding');
    }

    #[Test]
    public function the_task_poller_writes_its_own_finding_over_whatever_was_there(): void
    {
        /*
         * The poller's half on its own. The job reaches the poller carrying a
         * finding stamped with its last attempt — the state an operator's
         * verdict on a rebuild leaves, which settles the job without an
         * attempt and so without touching its finding — and the list's own
         * check cannot tell that one is stale. The poller's must replace it.
         */
        $job = $this->createJob(['hostname' => 'web-01-'.FakeComputeProvider::TASK_FAILURE_MARKER]);

        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status, (string) $job->last_error);

        $this->plantFinding($job, [
            'code' => CreateVpsHandler::IDENTITY_TAKEN,
            'class' => FailureClass::Permanent->value,
            'attempt' => $job->attempts,
            'reason' => CreateVpsHandler::REASON_NAMED_OTHERWISE,
            'reserved_provider_id' => $job->reserved_provider_id,
        ]);

        app(PollProviderTasks::class)->execute();

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame([
            'code' => PollProviderTasks::TASK_FAILED,
            'class' => FailureClass::Permanent->value,
            'attempt' => $job->attempts,
        ], $job->result['error'] ?? null);

        $this->assertSame(PollProviderTasks::TASK_FAILED, $this->reviewRowFor($job)['error_code'] ?? null);
    }

    #[Test]
    public function an_adoption_keeps_the_finding_it_resolved_in_its_own_record(): void
    {
        /*
         * The adoption's half on its own. It is recorded as an attempt, so a
         * finding it left in place would be an earlier attempt's.
         */
        $job = $this->createJob();
        $this->hypervisor->loseTheAnswerToCreates = true;

        $this->runWorker($job);
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $finding = $job->refresh()->result['error'] ?? null;
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $finding['code'] ?? null);

        $this->adoptAsOperator($job, (string) $job->reserved_provider_id)->assertOk();

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status);
        $this->assertArrayNotHasKey('error', $job->result ?? [], 'an adopted job still carries the finding of the attempt before the adoption');
        $this->assertSame($finding, $job->result['adoption']['finding'] ?? null);
    }

    #[Test]
    public function the_list_publishes_no_finding_from_an_attempt_before_the_last(): void
    {
        /*
         * The list's half on its own, with every writer's half out of the
         * picture: a finding still in place, written by an attempt before
         * the job's last one.
         */
        $job = $this->createJob();
        $this->aStrangerAt($this->derivedIdOf($job));

        $this->runWorker($job);
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $this->reviewRowFor($job->refresh())['error_code'] ?? null);

        DB::table('provisioning_jobs')->where('id', $job->id)->update(['attempts' => $job->attempts + 1]);

        $row = $this->reviewRowFor($job);
        $this->assertNull($row['error_code'] ?? null, 'the list published a finding from an attempt before the job\'s last');
        $this->assertNull($row['error_reason'] ?? null);
        $this->assertSame($job->reserved_provider_id, $row['reserved_provider_id'] ?? null);
    }

    #[Test]
    public function the_list_publishes_no_finding_about_an_identity_the_job_no_longer_holds(): void
    {
        /*
         * The other dimension a finding goes stale in, the one the repoint
         * also refuses: after a repoint the last attempt's finding is about
         * the identity the job was moved off, and the row beside it names the
         * new one — which nobody has looked under yet, and whose next step is
         * the retry, not the runbook row for a stranger at the old id.
         */
        $job = $this->createJob();
        $taken = $this->derivedIdOf($job);
        $this->aStrangerAt($taken);

        $this->runWorker($job);
        $this->repointAsOperator($job)->assertOk();

        $job->refresh();
        $this->assertNotSame($taken, $job->reserved_provider_id);
        $this->assertSame($taken, $job->result['error']['reserved_provider_id'] ?? null);

        $row = $this->reviewRowFor($job);
        $this->assertNull($row['error_code'] ?? null, 'the list published a finding about an identity the job no longer holds');
        $this->assertNull($row['error_reason'] ?? null);
        $this->assertSame($job->reserved_provider_id, $row['reserved_provider_id'] ?? null);
    }

    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function reviewRowFor(ProvisioningJob $job): array
    {
        $row = collect($this->actingAs($this->operator())->getJson('/api/admin/provisioning/needs-review')->assertOk()->json('data'))
            ->firstWhere('id', $job->id);

        $this->assertIsArray($row, 'the job is not on the review list');

        return $row;
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    private function plantFinding(ProvisioningJob $job, array $finding): void
    {
        DB::table('provisioning_jobs')->where('id', $job->id)->update([
            'result' => json_encode([...($job->result ?? []), 'error' => $finding], JSON_THROW_ON_ERROR),
        ]);
    }
}
