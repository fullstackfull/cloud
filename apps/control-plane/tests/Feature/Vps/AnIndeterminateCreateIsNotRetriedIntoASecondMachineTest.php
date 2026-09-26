<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Actions\PollProviderTasks;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Provisioning\Application\Actions\DetectStaleJobs;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * F-15: an indeterminate VPS create must not be retryable into a second
 * machine.
 *
 * The defect, as the audit found it: the create picked its VMID with a fresh
 * `random_int` on every attempt and wrote it down nowhere. A create whose
 * answer was lost — the cluster accepted it, the HTTP request was abandoned —
 * left the job with no task id and no provider reference, so
 * `RetryProvisioningJob`'s "something was built" refusal had nothing to read.
 * An operator's retry was accepted, drew a new id, and built a second machine
 * beside the first: one billed, one orphaned and holding a customer address.
 * Automatic retry was never the problem — a timeout is not retried by the
 * engine — so every test here drives the operator's path.
 *
 * The repair reserves the identity on the job before the call, records each
 * name a create under it is sent with immediately before it is sent, and
 * makes every attempt look under the identity before it builds. What the look
 * finds, and what each finding licenses, is what the rest of this file pins —
 * including that nothing found while no create is recorded as sent is
 * claimed: on a first attempt, under an identity a repoint gave, after
 * attempts that sent nothing, and at a pinned id on a first attempt and on a
 * second whose first is shown to have run after sends were recorded. The one
 * place a name is judged as sent with none recorded — a pinned id on a job
 * whose earlier attempts are not all shown to have run after sends were
 * recorded, so that one of them may have sent a create without recording it —
 * has its own tests, and its residual is the handler's to state.
 * The repoint that a stranger's machine licenses has its own file; the bound
 * on the payload that the ownership rule rests on has two (a behavioural pin
 * in this band, and a static census in the Provisioning band that fails on a
 * new writer in every form its shapes list — and names forms it does not
 * read).
 */
final class AnIndeterminateCreateIsNotRetriedIntoASecondMachineTest extends TestCase
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
    public function an_operator_retry_of_a_create_whose_answer_was_lost_builds_no_second_machine(): void
    {
        $job = $this->createJob();

        $this->runWorker($job);

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
        $this->assertCount(1, $this->hypervisor->everyMachine(), 'the first attempt built one machine and lost the answer');

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertCount(
            1,
            $this->hypervisor->everyMachine(),
            'the retry built a second machine beside the one the first attempt built: the customer now has one '
            .'billed server and one orphan holding an address.',
        );

        // Counted at the hypervisor's door as well as in its fleet: a second
        // create sent under the same id lands on the same (node, id) in the
        // simulator and would leave the fleet at one machine.
        $this->assertCount(1, $this->hypervisor->creates, 'the retry sent a second create');
    }

    #[Test]
    public function a_lost_answer_is_recorded_against_the_identity_it_was_about(): void
    {
        /*
         * The finding a lost answer leaves, and the attempt's own record,
         * each name the identity the create was sent under — as every finding
         * about an identity does, so that each says on its own which machine
         * it is about.
         */
        $job = $this->createJob();

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(FailureClass::Timeout, $job->failure_class);
        $this->assertNotNull($job->reserved_provider_id);
        $this->assertSame($job->reserved_provider_id, $job->result['error']['reserved_provider_id'] ?? null);

        $record = $job->attemptRecords()->where('attempt_number', 1)->firstOrFail();
        $this->assertSame($job->reserved_provider_id, $record->response_metadata['reserved_provider_id'] ?? null);
    }

    #[Test]
    public function the_retried_attempt_settles_carrying_its_own_machine_so_the_next_retry_is_refused(): void
    {
        $job = $this->createJob();

        $this->runWorker($job);
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $reserved = (string) $job->reserved_provider_id;

        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_AS_CALLED, $job->result['error']['reason'] ?? null);
        $this->assertSame($reserved, $job->result['provider_reference'] ?? null);

        // Only one create was ever sent: the retry looked and did not build.
        $this->assertCount(1, $this->hypervisor->creates);

        // From here the retry refusal holds, and the way out is adoption.
        $this->retryAsOperator($job)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'provisioning.retry_would_duplicate');

        $this->adoptAsOperator($job, $reserved)->assertOk();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status);
        $this->assertCount(1, $this->hypervisor->everyMachine());
    }

    #[Test]
    public function the_identity_is_written_down_before_the_provider_is_called(): void
    {
        $job = $this->createJob();
        $seen = [];

        $this->hypervisor->atTheMomentOfCreate = function (CreateVmRequest $request) use ($job, &$seen): void {
            $seen = (array) DB::table('provisioning_jobs')->where('id', $job->id)->first();
            $seen['requested_vmid'] = $request->vmId;
        };

        $this->runWorker($job);

        $this->assertArrayHasKey('reserved_provider_id', $seen);
        $this->assertSame((string) $seen['requested_vmid'], $seen['reserved_provider_id']);
        $this->assertSame($this->cluster->id, $seen['reserved_cluster_id']);
        $this->assertSame(['pve-01'], json_decode((string) $seen['reserved_provider_nodes'], true));
        $this->assertSame(['web-01'], json_decode((string) $seen['reserved_provider_hostnames'], true));
    }

    #[Test]
    public function every_attempt_of_one_job_asks_for_the_same_identity(): void
    {
        $job = $this->createJob(['hostname' => 'web-01-'.FakeComputeProvider::PROVIDER_FAILURE_MARKER]);
        $this->hypervisor->loseTheAnswerToCreates = false;

        // A refusal is transient, so the engine itself tries again.
        $this->runWorker($job);
        $this->runWorker($job);

        $this->assertCount(2, $this->hypervisor->creates);
        $this->assertSame($this->hypervisor->creates[0]->vmId, $this->hypervisor->creates[1]->vmId);
        $this->assertSame($this->derivedIdOf($job), (string) $this->hypervisor->creates[0]->vmId);
    }

    #[Test]
    public function a_retry_placed_on_another_node_still_finds_the_build_on_the_first(): void
    {
        $this->addNode('pve-02');
        $job = $this->createJob();

        $this->runWorker($job);

        $first = (string) ($job->refresh()->reserved_provider_nodes[0] ?? '');
        $this->assertNotSame('', $first);

        // The node the first attempt used goes into maintenance, so the retry
        // is placed on the other one — where nothing of this job's exists.
        DB::table('compute_nodes')->where('provider_name', $first)->update(['status' => 'maintenance']);

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $this->assertCount(1, $this->hypervisor->everyMachine());
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null);

        // Found before this attempt was placed or reserved anything: the
        // identity's nodes are looked under first, so the node list did not
        // grow and no second address was taken to be quarantined for nothing.
        $this->assertSame([$first], $job->reserved_provider_nodes);
        $this->assertSame(1, IpAddress::query()->where('status', IpAddressStatus::Quarantined)->count());
    }

    #[Test]
    public function a_stranger_on_a_node_no_earlier_attempt_used_is_found_before_building_there(): void
    {
        $this->addNode('pve-02');
        $job = $this->createJob(['hostname' => 'web-01-'.FakeComputeProvider::PROVIDER_FAILURE_MARKER]);

        // Refused outright: nothing built, and the engine will try again.
        $this->runWorker($job);

        $first = (string) ($job->refresh()->reserved_provider_nodes[0] ?? '');
        $second = $first === 'pve-01' ? 'pve-02' : 'pve-01';

        DB::table('compute_nodes')->where('provider_name', $first)->update(['status' => 'maintenance']);
        $this->aStrangerAt((string) $job->reserved_provider_id, node: $second);

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);
        $this->assertSame([$first, $second], $job->reserved_provider_nodes);
        $this->assertCount(1, $this->hypervisor->creates, 'the second attempt built beside a stranger');
    }

    #[Test]
    public function a_strangers_machine_at_the_identity_is_reported_and_nothing_is_built(): void
    {
        $job = $this->createJob();
        $this->aStrangerAt($this->derivedIdOf($job));

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Failed, $job->status);
        $this->assertSame(FailureClass::Permanent, $job->failure_class);
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);
        $this->assertArrayNotHasKey('provider_reference', $job->result ?? []);

        $this->assertSame([], $this->hypervisor->creates);
        $this->assertSame(0, VirtualMachine::query()->count());

        // Nothing of this build's exists, so its address goes back.
        $this->assertSame(0, IpAddress::query()->whereIn('status', [IpAddressStatus::Reserved, IpAddressStatus::Quarantined])->count());
    }

    #[Test]
    public function a_machine_there_before_any_create_was_sent_is_not_claimed_whatever_it_is_called(): void
    {
        /*
         * The round-three verification's reproduction, kept. A stranger at
         * the derived id, named exactly as this build names its machine and
         * shaped exactly as its plan: on a first attempt no create has been
         * sent under the identity, so nothing there can be this build's. It
         * used to be claimed — FOUND_ITS_OWN_BUILD, the stranger's id as this
         * job's provider reference, and "an earlier attempt of this build
         * already created machine …, adopt that machine" on a job that had
         * made one attempt and sent nothing. Adopting it, and the runbook's
         * DBA step after, hands one customer another customer's machine.
         */
        $job = $this->createJob();
        $this->aMachineShapedAsThisBuildAt($this->derivedIdOf($job), name: 'web-01');

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(1, $job->attempts);
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);
        $this->assertArrayNotHasKey('provider_reference', $job->result ?? []);
        $this->assertStringNotContainsString('earlier attempt', (string) $job->last_error);
        $this->assertSame([], $this->hypervisor->creates);
        $this->assertNull($job->reserved_provider_hostnames, 'a name was recorded as sent when nothing was');

        // Nothing of this build's is at the id, so the finding licenses the
        // way out that builds elsewhere, and adoption is not the way out.
        $this->repointAsOperator($job)->assertOk();
    }

    #[Test]
    public function a_machine_at_an_identity_a_repoint_gave_is_not_claimed_before_a_create_under_it_was_sent(): void
    {
        /*
         * The other "first attempt": the first one under the identity a
         * repoint gave. The repoint starts the new identity's names empty,
         * and a machine named as this build names its machine, already at
         * the new id, is not this build's either.
         */
        $job = $this->createJob();
        $this->aStrangerAt($this->derivedIdOf($job));
        $this->runWorker($job);

        $moved = (string) $this->repointAsOperator($job)->assertOk()->json('data.reserved_provider_id');
        $this->aMachineShapedAsThisBuildAt($moved, name: 'web-01');

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);
        $this->assertSame($moved, $job->result['error']['reserved_provider_id'] ?? null);
        $this->assertArrayNotHasKey('provider_reference', $job->result ?? []);
        $this->assertSame([], $this->hypervisor->creates);
    }

    #[Test]
    public function names_a_create_under_the_old_identity_was_sent_with_are_not_carried_to_the_new_one(): void
    {
        /*
         * The same property, where it can fail: a create under the first
         * identity HAS been sent, so there is a name recorded for the repoint
         * to leave behind. The create is lost before the cluster acts on it,
         * a stranger takes the id, the retry reports it named otherwise, and
         * the job is repointed. At the new id is a machine named as this
         * build names its machine and shaped as its plan. No create has been
         * sent under the new id, so it is not this build's — and it would be
         * claimed, with a message saying an earlier attempt sent a create
         * under the new id, if the old identity's names came with the job.
         */
        $job = $this->createJob();
        $this->hypervisor->loseTheRequestToCreates = true;

        try {
            $this->runWorker($job);
        } finally {
            $this->hypervisor->loseTheRequestToCreates = false;
        }

        $old = (string) $job->refresh()->reserved_provider_id;
        $this->assertSame(['web-01'], $job->reserved_provider_hostnames);

        $this->aStrangerAt($old);
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->refresh()->result['error']['reason'] ?? null);

        $moved = (string) $this->repointAsOperator($job)->assertOk()->json('data.reserved_provider_id');
        $this->assertNotSame($old, $moved);
        $this->aMachineShapedAsThisBuildAt($moved, name: 'web-01');

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);
        $this->assertSame($moved, $job->result['error']['reserved_provider_id'] ?? null);
        $this->assertArrayNotHasKey('provider_reference', $job->result ?? []);
        $this->assertNull($job->reserved_provider_hostnames, 'the old identity\'s names came with the job to the new one');
        $this->assertCount(1, $this->hypervisor->creates, 'a create was sent under the new identity');
    }

    #[Test]
    public function every_name_a_create_under_the_identity_was_sent_with_is_kept_not_only_the_latest(): void
    {
        /*
         * The list is append-only because the comparison is against every
         * name a create under the identity was sent with. Nothing on the
         * platform edits a payload, so in practice there is one; the list is
         * what keeps the comparison right if something outside the platform
         * does. Here a create is sent as "web-01" and lost before the
         * cluster acts on it, the payload is changed by hand to "web-02",
         * and the retry's create is lost the same way. A machine named
         * "web-01" at the identity afterwards carries a name a create under
         * it was sent with, and is not called a stranger's — which it would
         * be, licensing a repoint and a build beside it, if the second name
         * had replaced the first.
         */
        $job = $this->createJob();
        $this->hypervisor->loseTheRequestToCreates = true;

        try {
            $this->runWorker($job);
            DB::table('provisioning_jobs')->where('id', $job->id)->update([
                'payload' => json_encode([...$job->refresh()->payload, 'hostname' => 'web-02']),
            ]);
            $this->retryAsOperator($job)->assertOk();
            $this->runWorker($job);
        } finally {
            $this->hypervisor->loseTheRequestToCreates = false;
        }

        $job->refresh();
        $this->assertSame(['web-01', 'web-02'], $job->reserved_provider_hostnames);

        // A name already held is not held twice: a list of names, not a log of sends.
        $job->recordCreateSentWith('web-01');
        $this->assertSame(['web-01', 'web-02'], $job->refresh()->reserved_provider_hostnames);

        $this->aMachineShapedAsThisBuildAt((string) $job->reserved_provider_id, name: 'web-01');
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_AS_CALLED, $job->result['error']['reason'] ?? null);
        $this->assertCount(2, $this->hypervisor->creates, 'the third attempt built beside the machine the first create made');
    }

    #[Test]
    public function an_attempt_that_reserved_the_identity_and_sent_nothing_gives_the_next_nothing_to_claim_by(): void
    {
        /*
         * An identity is reserved before capacity and the address, so an
         * attempt can hold one and end without sending a create — here, on
         * an exhausted pool, which the engine retries by itself. What a later
         * attempt judges a machine at the identity against is the names a
         * create was SENT with, and this one sent none.
         */
        $job = $this->createJob();
        $free = IpAddress::query()->where('status', IpAddressStatus::Available)->pluck('id');
        IpAddress::query()->whereIn('id', $free)->update(['status' => IpAddressStatus::Unavailable]);

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status, (string) $job->last_error);
        $this->assertSame(FailureClass::Capacity, $job->failure_class);
        $this->assertNotNull($job->reserved_provider_id);
        $this->assertSame([], $this->hypervisor->creates);

        IpAddress::query()->whereIn('id', $free)->update(['status' => IpAddressStatus::Available]);
        $this->aMachineShapedAsThisBuildAt((string) $job->reserved_provider_id, name: 'web-01');

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);
        $this->assertArrayNotHasKey('provider_reference', $job->result ?? []);
        $this->assertSame([], $this->hypervisor->creates);
    }

    #[Test]
    public function a_create_sent_alongside_this_attempt_is_read_after_the_look_not_before_it(): void
    {
        /*
         * Two workers can hold one job: the stale sweep moves a slow worker's
         * job to review, and an operator's retry hands it to a second. The
         * names a create was sent with are therefore read when a machine has
         * been found, not taken from what the row held when this attempt
         * reserved the identity. Here the other worker sends its create —
         * and it builds — between this attempt's reservation and its look.
         * Judged against the names as they were before, the other worker's
         * machine would be a stranger's, licensing a repoint and a second
         * build.
         */
        $job = $this->createJob();
        $this->hypervisor->loseTheAnswerToCreates = false;

        $this->hypervisor->atTheMomentOfLook = function (string $node, string $providerId) use ($job): void {
            ProvisioningJob::query()->findOrFail($job->id)->recordCreateSentWith('web-01');
            $this->aMachineShapedAsThisBuildAt($providerId, name: 'web-01', node: $node);
        };

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_AS_CALLED, $job->result['error']['reason'] ?? null);
        $this->assertSame([], $this->hypervisor->creates, 'this attempt built beside the other worker\'s machine');
    }

    #[Test]
    public function an_id_pinned_on_the_payload_of_a_job_attempted_before_is_judged_as_if_its_name_had_been_sent(): void
    {
        /*
         * The one case in which "nothing recorded" is not "nothing sent". An
         * id pinned on the payload was honoured before creates were recorded
         * — the create used it directly — so an attempt made then may have
         * sent a create under it with the payload's name and recorded
         * nothing. Here that attempt is the job's first: it sent the create,
         * its answer was lost, and it left the job for review with nothing
         * reserved, nothing recorded and no finding stamped with it, exactly
         * as the create and the engine did before F-15. The machine it built
         * is at the pinned id. The operator's retry is the job's second
         * attempt, and a machine at the pinned id carrying the payload's name
         * and this build's shape is taken to be this build's — and the
         * operator is told why, without being told a create under the id is
         * recorded as sent.
         *
         * Only where an earlier attempt is not shown to have run after sends
         * were recorded: see
         * a_first_attempt_at_an_id_pinned_on_the_payload_claims_nothing_there
         * and the two second attempts after it.
         */
        $job = $this->createJob(['vm_id' => 4242], [
            'status' => ProvisioningJobStatus::NeedsReview,
            'attempts' => 1,
            'failure_class' => FailureClass::Timeout,
            'last_error' => 'The request to the cluster timed out; it may have been accepted.',
        ]);
        $this->aMachineShapedAsThisBuildAt('4242', name: 'web-01');

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(2, $job->attempts);
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_AS_CALLED, $job->result['error']['reason'] ?? null);
        $this->assertTrue($job->result['response']['payload_name_taken_as_sent'] ?? null);
        $this->assertSame('4242', $job->result['provider_reference'] ?? null);
        $this->assertNull($job->reserved_provider_hostnames);
        $this->assertStringNotContainsString('was sent by this build', (string) $job->last_error);
        $this->assertStringContainsString('pinned', (string) $job->last_error);
        $this->assertStringContainsString('attempted before', (string) $job->last_error);
        $this->assertSame([], $this->hypervisor->creates);
    }

    #[Test]
    public function a_first_attempt_at_an_id_pinned_on_the_payload_claims_nothing_there(): void
    {
        /*
         * The round-four verification's reproduction, kept. The pinned
         * exception rests on an earlier attempt that may have sent a create
         * before sends were recorded, and a first attempt has no earlier
         * attempt. A stranger at the pinned id, named as the payload names
         * this build's machine and shaped as its plan, used to be claimed
         * here all the same: FOUND_ITS_OWN_BUILD with the stranger's id as
         * this job's provider reference, retry and repoint both refused, and
         * adoption the only act left, on a job that had built nothing.
         */
        $job = $this->createJob(['vm_id' => 55555]);
        $this->aMachineShapedAsThisBuildAt('55555', name: 'web-01');

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(1, $job->attempts);
        $this->assertSame('55555', $job->reserved_provider_id);
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);
        $this->assertTrue($job->result['response']['pinned_on_the_payload'] ?? null);
        $this->assertFalse($job->result['response']['payload_name_taken_as_sent'] ?? null);
        $this->assertArrayNotHasKey('provider_reference', $job->result ?? []);
        $this->assertStringNotContainsString('pinned', (string) $job->last_error);
        $this->assertSame([], $this->hypervisor->creates);
        $this->assertNull($job->reserved_provider_hostnames);

        // The review row names the finding and offers nothing to adopt.
        $row = collect($this->actingAs($this->operator())->getJson('/api/admin/provisioning/needs-review')->assertOk()->json('data'))
            ->firstWhere('id', $job->id);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $row['error_reason'] ?? null);
        $this->assertNull($row['provider_reference'] ?? null, 'a job that built nothing is offered the stranger\'s machine to adopt');

        // Nothing of this build's is at the id: the way out is the one that
        // builds elsewhere, and it is not refused.
        $this->repointAsOperator($job)->assertOk();
        $this->retryAsOperator($job)->assertOk();
    }

    #[Test]
    public function a_second_attempt_at_a_pinned_id_whose_first_held_an_identity_and_sent_nothing_claims_nothing_there(): void
    {
        /*
         * The round-five verification's reproduction, kept. The job's only
         * earlier attempt reserved the pinned id — which only the create that
         * records every create it sends does — and stopped at an exhausted
         * pool, which the engine retries by itself, having sent nothing. A
         * stranger then takes the id, named as the payload names this build's
         * machine and shaped as its plan. It used to be claimed on the second
         * attempt: FOUND_ITS_OWN_BUILD with the stranger's id as this job's
         * provider reference, retry and repoint both refused, and the operator
         * told that a create may have been sent, on a job that had sent none.
         */
        $job = $this->createJob(['vm_id' => 55555]);
        $free = IpAddress::query()->where('status', IpAddressStatus::Available)->pluck('id');
        IpAddress::query()->whereIn('id', $free)->update(['status' => IpAddressStatus::Unavailable]);

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status, (string) $job->last_error);
        $this->assertSame('55555', $job->reserved_provider_id);
        $this->assertNull($job->reserved_provider_hostnames);

        IpAddress::query()->whereIn('id', $free)->update(['status' => IpAddressStatus::Available]);
        $this->aMachineShapedAsThisBuildAt('55555', name: 'web-01');

        $this->runWorker($job);

        $this->assertTheSecondAttemptClaimedNothingAtThePinnedId($job);
    }

    #[Test]
    public function a_second_attempt_at_a_pinned_id_whose_first_held_an_identity_and_was_swept_claims_nothing_there(): void
    {
        /*
         * The identity on its own, where the finding shows nothing: the first
         * attempt reserved the pinned id and its worker died before it sent
         * anything, and the stale sweep moved the job to review under a
         * finding of the sweep's. The identity the job holds is what shows
         * that the first attempt ran this create, which records every create
         * it sends; none is recorded, so none was sent.
         */
        $job = $this->createJob(['vm_id' => 55555]);

        $this->runWorkerThatDiesAtTheLook($job);

        DB::table('provisioning_jobs')->where('id', $job->id)->update(['started_at' => now()->subDay()]);
        app(DetectStaleJobs::class)->execute();

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(DetectStaleJobs::ERROR_CODE, $job->result['error']['code'] ?? null);
        $this->assertSame('55555', $job->reserved_provider_id);
        $this->assertNull($job->reserved_provider_hostnames);

        $this->aMachineShapedAsThisBuildAt('55555', name: 'web-01');
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertTheSecondAttemptClaimedNothingAtThePinnedId($job);
    }

    #[Test]
    public function a_second_attempt_at_a_pinned_id_whose_first_left_the_engines_finding_claims_nothing_there(): void
    {
        /*
         * The same, where the first attempt stopped before it reserved
         * anything — no node had room, which the engine also retries by
         * itself — so nothing is held. What shows that attempt ran after sends
         * were recorded is the finding the job carries: the engine that ran
         * it wrote it, stamped with it, and the engine has stamped its
         * findings only since sends were recorded. It recorded every create it
         * sent, and it sent none.
         */
        $job = $this->createJob(['vm_id' => 55555]);
        DB::table('compute_nodes')->where('id', $this->node->id)->update(['status' => NodeStatus::Maintenance->value]);

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status, (string) $job->last_error);
        $this->assertSame(FailureClass::Capacity, $job->failure_class);
        $this->assertNull($job->reserved_provider_id);
        $this->assertSame(1, $job->result['error']['attempt'] ?? null);

        DB::table('compute_nodes')->where('id', $this->node->id)->update(['status' => NodeStatus::Active->value]);
        $this->aMachineShapedAsThisBuildAt('55555', name: 'web-01');

        $this->runWorker($job);

        $this->assertTheSecondAttemptClaimedNothingAtThePinnedId($job);
    }

    #[Test]
    public function a_finding_the_stale_sweep_stamped_does_not_show_what_the_attempt_recorded(): void
    {
        /*
         * The stale sweep stamps its finding with the attempt it is about, and
         * it did not run that attempt. Here the first attempt is one made
         * before sends were recorded: it sent a create under the pinned id and
         * its worker died, leaving the job running with nothing reserved and
         * nothing recorded, and the machine it built at the id. The sweep —
         * running after sends were recorded — moves the job to review,
         * stamping its finding with attempt 1. Read as the engine's own, that
         * finding would call the machine a stranger's, and the repoint it
         * licensed would build a second machine beside this build's own.
         */
        $job = $this->createJob(['vm_id' => 4242], [
            'status' => ProvisioningJobStatus::Running,
            'attempts' => 1,
            'started_at' => now()->subDay(),
        ]);
        $this->aMachineShapedAsThisBuildAt('4242', name: 'web-01');

        app(DetectStaleJobs::class)->execute();

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(DetectStaleJobs::ERROR_CODE, $job->result['error']['code'] ?? null);
        $this->assertSame(1, $job->result['error']['attempt'] ?? null);

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertTheSecondAttemptTookThePayloadsNameAsSent($job, '4242');
        $this->repointAsOperator($job)->assertStatus(409);
    }

    #[Test]
    public function a_finding_the_task_poller_stamped_does_not_show_what_the_attempt_recorded(): void
    {
        /*
         * The task poller, like the sweep, stamps a finding about an attempt
         * it did not run. No route on the platform today runs a job again
         * after the poller has written one — it writes one only on a job whose
         * create was answered, and a retry of that job is refused — so this
         * state is written directly: the rule that a finding shows what an
         * attempt recorded only when the engine that ran it wrote it does not
         * lean on that.
         */
        $job = $this->createJob(['vm_id' => 4242], [
            'attempts' => 1,
            'result' => ['error' => [
                'code' => PollProviderTasks::TASK_FAILED,
                'class' => FailureClass::Permanent->value,
                'attempt' => 1,
            ]],
        ]);
        $this->aMachineShapedAsThisBuildAt('4242', name: 'web-01');

        $this->runWorker($job);

        $this->assertTheSecondAttemptTookThePayloadsNameAsSent($job, '4242');
    }

    #[Test]
    public function a_finding_written_before_findings_were_stamped_does_not_show_what_the_attempt_recorded(): void
    {
        /*
         * Before sends were recorded, the engine wrote its finding as a code
         * and a class, with no attempt stamp. The first attempt here left one,
         * for a create whose answer was lost: it ran before sends were
         * recorded, and an unstamped finding is not read as showing
         * otherwise, whatever its code.
         */
        $job = $this->createJob(['vm_id' => 4242], [
            'status' => ProvisioningJobStatus::NeedsReview,
            'attempts' => 1,
            'failure_class' => FailureClass::Timeout,
            'result' => ['error' => ['code' => 'compute.provider_request_failed', 'class' => FailureClass::Timeout->value]],
        ]);
        $this->aMachineShapedAsThisBuildAt('4242', name: 'web-01');

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->assertTheSecondAttemptTookThePayloadsNameAsSent($job, '4242');
    }

    #[Test]
    public function a_pinned_id_on_a_third_attempt_is_judged_as_if_its_name_had_been_sent(): void
    {
        /*
         * The handler's declared residual, pinned so that closing it is a
         * decision and not an accident. From a job's third attempt on, which
         * code each earlier attempt ran is not read, and at a pinned id with
         * no name recorded the payload's name is judged as sent — here
         * although both earlier attempts held the identity and stopped at an
         * exhausted pool, having sent nothing. The operator is told how many
         * attempts there were, and to confirm the machine at the node.
         */
        $job = $this->createJob(['vm_id' => 55555]);
        $free = IpAddress::query()->where('status', IpAddressStatus::Available)->pluck('id');
        IpAddress::query()->whereIn('id', $free)->update(['status' => IpAddressStatus::Unavailable]);

        $this->runWorker($job);
        $this->runWorker($job);

        $this->assertSame(ProvisioningJobStatus::Queued, $job->refresh()->status, (string) $job->last_error);
        IpAddress::query()->whereIn('id', $free)->update(['status' => IpAddressStatus::Available]);
        $this->aMachineShapedAsThisBuildAt('55555', name: 'web-01');

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(3, $job->attempts);
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null);
        $this->assertTrue($job->result['response']['payload_name_taken_as_sent'] ?? null);
        $this->assertStringContainsString('attempted 2 times before', (string) $job->last_error);
        $this->assertStringContainsString('confirm it at the node', (string) $job->last_error);
        $this->assertSame([], $this->hypervisor->creates);
    }

    #[Test]
    public function a_name_recorded_as_sent_at_a_pinned_id_is_judged_as_recorded_not_as_the_payloads(): void
    {
        /*
         * The payload's name is judged as sent only while none is recorded.
         * Here creates under the pinned id have been sent — each recorded,
         * each lost before the cluster acted on it — and on the third attempt
         * a machine of that name is there. The name matched is one recorded as
         * sent, and the operator is told so; not that no create is recorded,
         * which is what the pinned judgement would say. A third attempt,
         * because on a second one whose first recorded its send the pinned
         * judgement is ruled out on other grounds as well.
         */
        $job = $this->createJob(['vm_id' => 4242]);
        $this->hypervisor->loseTheRequestToCreates = true;

        try {
            $this->runWorker($job);
            $this->retryAsOperator($job)->assertOk();
            $this->runWorker($job);
        } finally {
            $this->hypervisor->loseTheRequestToCreates = false;
        }

        $job->refresh();
        $this->assertSame(['web-01'], $job->reserved_provider_hostnames);
        $this->aMachineShapedAsThisBuildAt('4242', name: 'web-01');

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(3, $job->attempts);
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null);
        $this->assertFalse($job->result['response']['payload_name_taken_as_sent'] ?? null);
        $this->assertStringContainsString('A create under provider identity 4242 was sent by this build with the name "web-01"', (string) $job->last_error);
        $this->assertStringNotContainsString('No create under the id is recorded as sent', (string) $job->last_error);
        $this->assertCount(2, $this->hypervisor->creates);
    }

    #[Test]
    public function a_machine_that_reports_no_name_is_not_read_as_somebody_elses(): void
    {
        /*
         * Proxmox omits the name while qmcreate is still writing the config,
         * and that is exactly when a retry after a lost answer arrives. Read
         * as "not named web-01", this build's own half-made machine would be
         * a stranger that licenses a repoint and a second build.
         */
        $job = $this->createJob(['hostname' => 'web-01-'.FakeComputeProvider::UNNAMED_MARKER]);

        $this->runWorker($job);
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(FailureClass::Timeout, $job->failure_class);
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_UNNAMED, $job->result['error']['reason'] ?? null);
        $this->assertCount(1, $this->hypervisor->everyMachine());
    }

    /*
     * The four tests below judge a machine found at the identity by its name
     * and shape, which only means anything once a create under the identity
     * has been sent with a name: before that, nothing there is this build's
     * whatever it is called (see
     * a_machine_there_before_any_create_was_sent_is_not_claimed_whatever_it_is_called).
     * They used to put the machine there before the job's first attempt, and
     * so pinned exactly the claim the round-three verification falsified —
     * the last of them asserted that a stranger was claimed as this build's on
     * an attempt that had sent nothing. Each now first sends a create that
     * builds nothing, which is the state in which the rules they pin decide
     * anything.
     */

    #[Test]
    public function a_machine_named_as_called_but_shaped_otherwise_is_not_claimed_either_way(): void
    {
        $job = $this->createJob();
        $this->aCreateWasSentAndBuiltNothing($job);
        $this->aStrangerAt($this->derivedIdOf($job), name: 'web-01');

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(CreateVpsHandler::REASON_SHAPE_DIFFERS, $job->result['error']['reason'] ?? null);
        $this->assertCount(1, $this->hypervisor->creates, 'the retry built beside the machine it could not place');
    }

    #[Test]
    public function either_figure_differing_on_its_own_withholds_the_claim(): void
    {
        /*
         * vCPU and memory are each evidence on their own: a machine named as
         * called that differs from the plan in only one of them is not
         * claimed either.
         */
        foreach (['vcpu alone' => [8, 4096], 'memory alone' => [2, 16384]] as $case => [$vcpu, $memoryMib]) {
            $job = $this->createJob();
            $this->aCreateWasSentAndBuiltNothing($job);
            $this->aStrangerAt($this->derivedIdOf($job), name: 'web-01');
            $this->hypervisor->fleet->resizeVm('pve-01', $this->derivedIdOf($job), new ResizeVmRequest(vcpu: $vcpu, memoryMib: $memoryMib));

            $this->runWorker($job);

            $this->assertSame(
                CreateVpsHandler::REASON_SHAPE_DIFFERS,
                $job->refresh()->result['error']['reason'] ?? null,
                sprintf('A machine named as called, differing in %s, was claimed as this build\'s.', $case),
            );
        }
    }

    #[Test]
    public function a_figure_the_hypervisor_does_not_report_contradicts_nothing(): void
    {
        /*
         * A null vCPU or memory figure is the absence of an observation, not
         * an observation of a different shape. A machine named as a create
         * under this identity was sent, whose figures are not reported, is
         * recognised on its name — even though, had they been reported, they
         * would differ.
         */
        $job = $this->createJob();
        $this->aCreateWasSentAndBuiltNothing($job);
        $this->aStrangerAt($this->derivedIdOf($job), name: 'web-01');
        $this->hypervisor->reportNoFigures = true;

        $this->runWorker($job);

        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->refresh()->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_AS_CALLED, $job->result['error']['reason'] ?? null);
        $this->assertStringContainsString(
            sprintf('A create under provider identity %s was sent by this build with the name "web-01"', $this->derivedIdOf($job)),
            (string) $job->last_error,
        );
    }

    #[Test]
    public function the_identity_first_reserved_is_the_one_every_later_reservation_is_handed(): void
    {
        /*
         * First writer wins, in the statement itself: a caller holding a
         * copy of the job from before the reservation — a stale model, a
         * concurrent claim — that offers another id is handed the one held,
         * and its node joins the list rather than replacing it.
         */
        $job = $this->createJob();
        $stale = ProvisioningJob::query()->findOrFail($job->id);

        $first = $job->reserveProviderIdentity('20001', $this->cluster->id, 'pve-01');
        $second = $stale->reserveProviderIdentity('20002', $this->cluster->id, 'pve-02');

        $this->assertSame('20001', $first?->providerId);
        $this->assertSame('20001', $second?->providerId);
        $this->assertSame(['pve-01', 'pve-02'], $second->nodes);
        $this->assertSame('20001', $stale->reserved_provider_id);
        $this->assertSame('20001', DB::table('provisioning_jobs')->where('id', $job->id)->value('reserved_provider_id'));
    }

    #[Test]
    public function an_identity_is_not_reserved_again_against_another_cluster(): void
    {
        /*
         * The cluster is a condition of the statement, not only a value it
         * writes: a reservation offered against another cluster writes
         * nothing and is answered with null, whatever its caller was told.
         */
        $job = $this->createJob();
        $other = ComputeCluster::factory()->create(['driver' => 'fake']);

        $job->reserveProviderIdentity('20001', $this->cluster->id, 'pve-01');

        $this->assertNull($job->reserveProviderIdentity('20001', $other->id, 'pve-09'));

        $row = DB::table('provisioning_jobs')->where('id', $job->id)->first();
        $this->assertSame($this->cluster->id, $row?->reserved_cluster_id);
        $this->assertSame(['pve-01'], json_decode((string) $row?->reserved_provider_nodes, true));
    }

    #[Test]
    public function the_name_is_compared_exactly_in_both_directions_a_loosening_could_take(): void
    {
        /*
         * What the job claims as its own, it may later be told to build
         * around; so the comparison claims only the names it sent, byte for
         * byte. Case folding would claim "WEB-01"; a prefix or substring match
         * would claim "web-01-old". Both are somebody else's.
         */
        foreach (['WEB-01', 'web-01-old', 'old-web-01', ' web-01'] as $index => $name) {
            $job = $this->createJob();
            $this->aCreateWasSentAndBuiltNothing($job);

            // Same shape as the plan, so only the name decides.
            $this->aMachineShapedAsThisBuildAt($this->derivedIdOf($job), name: $name);

            $this->runWorker($job);

            $this->assertSame(
                CreateVpsHandler::REASON_NAMED_OTHERWISE,
                $job->refresh()->result['error']['reason'] ?? null,
                sprintf('A machine named "%s" was claimed by a job that asked for "web-01" (case %d).', $name, $index),
            );
        }
    }

    #[Test]
    public function a_payload_naming_another_cluster_does_not_build_under_the_reserved_identity(): void
    {
        $job = $this->createJob();

        $this->runWorker($job);
        $this->assertNotNull($job->refresh()->reserved_cluster_id);

        // Nothing on the platform edits a payload; this is a write from
        // outside it, which is the only way the two can come to disagree.
        $other = ComputeCluster::factory()->create(['driver' => 'fake']);
        DB::table('provisioning_jobs')->where('id', $job->id)->update([
            'payload' => DB::raw("jsonb_set(payload, '{cluster_id}', to_jsonb('".$other->id."'::text))"),
        ]);

        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::IDENTITY_RESERVED_ELSEWHERE, $job->result['error']['code'] ?? null);
        $this->assertSame($this->cluster->id, $job->reserved_cluster_id);
        $this->assertCount(1, $this->hypervisor->creates);
    }

    #[Test]
    public function a_hypervisor_that_cannot_be_asked_is_not_built_on(): void
    {
        $job = $this->createJob();
        $this->hypervisor->loseTheAnswerToCreates = false;

        $this->hypervisor->failReadsWith = ComputeProviderException::requestFailed('fake', 'get_vm', [
            'provider_message' => 'the node did not answer',
        ]);

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::IDENTITY_UNVERIFIABLE, $job->result['error']['code'] ?? null);
        $this->assertSame(FailureClass::Transient, $job->failure_class);
        $this->assertSame([], $this->hypervisor->creates);
    }

    #[Test]
    public function an_id_pinned_on_the_payload_is_used_only_until_an_identity_is_reserved(): void
    {
        $job = $this->createJob(['vm_id' => 4242]);

        $this->runWorker($job);

        $this->assertSame('4242', $job->refresh()->reserved_provider_id);
        $this->assertSame(4242, $this->hypervisor->creates[0]->vmId);
    }

    #[Test]
    public function adopting_a_vps_leaves_it_unmanaged_and_its_address_in_quarantine(): void
    {
        /*
         * A characterization, not an endorsement. Adoption is generic and
         * shared with shared hosting: it records the job and delivers the
         * service, and it creates no machine row and commits no address — the
         * address it would need was quarantined when the first attempt timed
         * out. The runbook tells the operator so and what to do about it by
         * hand; this test is what makes that paragraph a measurement, and
         * closing either gap breaks it and points at the paragraph.
         */
        $job = $this->createJob();

        $this->runWorker($job);
        $this->retryAsOperator($job)->assertOk();
        $this->runWorker($job);

        $this->adoptAsOperator($job, (string) $job->refresh()->reserved_provider_id)->assertOk();

        $this->assertSame(ServiceStatus::Active, $job->service()->firstOrFail()->status);
        $this->assertSame(0, VirtualMachine::query()->count());
        $this->assertSame(1, IpAddress::query()->where('status', IpAddressStatus::Quarantined)->count());
        $this->assertSame(0, IpAddress::query()->where('status', IpAddressStatus::Assigned)->count());
    }

    #[Test]
    public function the_review_screen_shows_what_the_runbook_tells_the_operator_to_read(): void
    {
        $job = $this->createJob();
        $this->aStrangerAt($this->derivedIdOf($job));

        $this->runWorker($job);

        $row = collect($this->actingAs($this->operator())->getJson('/api/admin/provisioning/needs-review')->assertOk()->json('data'))
            ->firstWhere('id', $job->id);

        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $row['error_code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $row['error_reason'] ?? null);
        $this->assertSame($this->derivedIdOf($job), $row['reserved_provider_id'] ?? null);
        $this->assertSame($this->cluster->id, $row['reserved_cluster_id'] ?? null);
        $this->assertSame(['pve-01'], $row['reserved_provider_nodes'] ?? null);

        // The names a create under the identity was sent with: none, because
        // the stranger was found before one was. This used to read
        // ['web-01'], a name recorded at the reservation and never sent,
        // which is what let a same-named stranger be claimed.
        $this->assertSame([], $row['reserved_provider_hostnames'] ?? null);
    }

    /**
     * One attempt that sends a create under the job's identity and builds
     * nothing — the request lost before the cluster acted on it — and the
     * operator's retry of it. The job is left queued, holding its identity,
     * with the name its create was sent with recorded and nothing at the id.
     */
    private function aCreateWasSentAndBuiltNothing(ProvisioningJob $job): void
    {
        $this->hypervisor->loseTheRequestToCreates = true;

        try {
            $this->runWorker($job);
        } finally {
            $this->hypervisor->loseTheRequestToCreates = false;
        }

        $job->refresh();
        $this->assertSame(['web-01'], $job->reserved_provider_hostnames);
        $this->assertNull($this->hypervisor->fleet->getVm('pve-01', (string) $job->reserved_provider_id));

        $this->retryAsOperator($job)->assertOk();
    }

    /**
     * One attempt whose worker is killed after it has reserved the job's
     * identity and before it has sent anything: at its look under the
     * identity. The job is left running with the attempt counted, the
     * identity held, and nothing settled or recorded as sent — for the stale
     * sweep to find. The claim is reached by reflection for the reason
     * runWorkerThatDiesAfterBuilding() gives: the engine catches every
     * throwable, and a dead worker catches nothing.
     */
    private function runWorkerThatDiesAtTheLook(ProvisioningJob $job): void
    {
        $engine = new RunProvisioningJob((string) $job->getKey());
        $claim = new ReflectionMethod($engine, 'claim');

        /** @var ProvisioningJob $claimed */
        $claimed = $claim->invoke($engine, app(ProvisioningJobStateMachine::class));

        $death = new RuntimeException('the worker was killed');
        $this->hypervisor->atTheMomentOfLook = static function () use ($death): void {
            throw $death;
        };

        try {
            app(CreateVpsHandler::class)->execute($claimed);
            $this->fail('The attempt did not look under its identity, so there was nothing for the worker to die at.');
        } catch (RuntimeException $e) {
            $this->assertSame($death, $e, 'The attempt failed for another reason: '.$e->getMessage());
        }
    }

    /**
     * The job's second attempt found a machine at pinned id 55555, named as
     * the payload names this build's machine and shaped as its plan, and
     * claimed nothing: its one earlier attempt is shown to have run after
     * sends were recorded, and none is recorded, so none was sent and the
     * machine is somebody else's whatever it is called.
     */
    private function assertTheSecondAttemptClaimedNothingAtThePinnedId(ProvisioningJob $job): void
    {
        $job->refresh();
        $this->assertSame(2, $job->attempts);
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null, (string) $job->last_error);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);
        $this->assertTrue($job->result['response']['pinned_on_the_payload'] ?? null);
        $this->assertFalse($job->result['response']['payload_name_taken_as_sent'] ?? null);
        $this->assertArrayNotHasKey('provider_reference', $job->result ?? []);
        $this->assertStringContainsString('no create under this identity has been sent yet', (string) $job->last_error);
        $this->assertStringNotContainsString('pinned', (string) $job->last_error);
        $this->assertSame([], $this->hypervisor->creates);
        $this->assertNull($job->reserved_provider_hostnames);

        $row = collect($this->actingAs($this->operator())->getJson('/api/admin/provisioning/needs-review')->assertOk()->json('data'))
            ->firstWhere('id', $job->id);
        $this->assertNotNull($row, 'the job is not on the review list');
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $row['error_reason'] ?? null);
        $this->assertNull($row['provider_reference'] ?? null, 'a job that built nothing is offered the stranger\'s machine to adopt');

        // Nothing of this build's is at the id, so the way out that builds
        // elsewhere is not refused.
        $this->repointAsOperator($job)->assertOk();
    }

    /**
     * The job's second attempt found a machine at the pinned id named as the
     * payload names this build's machine, with no name recorded and its one
     * earlier attempt not shown to have run after sends were recorded: the
     * payload's name was judged as sent, the machine taken to be this
     * build's, and nothing new built.
     */
    private function assertTheSecondAttemptTookThePayloadsNameAsSent(ProvisioningJob $job, string $pinned): void
    {
        $job->refresh();
        $this->assertSame(2, $job->attempts);
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null, (string) $job->last_error);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_AS_CALLED, $job->result['error']['reason'] ?? null);
        $this->assertTrue($job->result['response']['payload_name_taken_as_sent'] ?? null);
        $this->assertSame($pinned, $job->result['provider_reference'] ?? null);
        $this->assertStringContainsString('its first attempt', (string) $job->last_error);
        $this->assertSame([], $this->hypervisor->creates);
        $this->assertCount(1, $this->hypervisor->everyMachine());
    }
}
