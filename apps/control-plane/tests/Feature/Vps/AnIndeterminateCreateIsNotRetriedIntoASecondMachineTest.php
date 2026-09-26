<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use PHPUnit\Framework\Attributes\Test;
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
 * The repair reserves the identity on the job before the call, and makes
 * every attempt look under it before it builds. What the look finds, and what
 * each finding licenses, is what the rest of this file pins. The repoint that
 * a stranger's machine licenses has its own file; the bound on the payload
 * that the ownership rule rests on has two (a behavioural pin in this band,
 * and a static census in the Provisioning band that fails on a new writer in
 * any form of the shapes it scans — and names the forms it cannot read).
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

    #[Test]
    public function a_machine_named_as_called_but_shaped_otherwise_is_not_claimed_either_way(): void
    {
        $job = $this->createJob();
        $this->aStrangerAt($this->derivedIdOf($job), name: 'web-01');

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(CreateVpsHandler::REASON_SHAPE_DIFFERS, $job->result['error']['reason'] ?? null);
        $this->assertSame([], $this->hypervisor->creates);
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
         * an observation of a different shape. A machine named as this job
         * called it, whose figures are not reported, is recognised on its
         * name — even though, had they been reported, they would differ.
         */
        $job = $this->createJob();
        $this->aStrangerAt($this->derivedIdOf($job), name: 'web-01');
        $this->hypervisor->reportNoFigures = true;

        $this->runWorker($job);

        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->refresh()->result['error']['code'] ?? null);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_AS_CALLED, $job->result['error']['reason'] ?? null);
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

        $first = $job->reserveProviderIdentity('20001', $this->cluster->id, 'pve-01', 'web-01');
        $second = $stale->reserveProviderIdentity('20002', $this->cluster->id, 'pve-02', 'web-01');

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

        $job->reserveProviderIdentity('20001', $this->cluster->id, 'pve-01', 'web-01');

        $this->assertNull($job->reserveProviderIdentity('20001', $other->id, 'pve-09', 'web-01'));

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
            $this->aStrangerAt($this->derivedIdOf($job), name: $name);

            // Same shape as the plan, so only the name decides.
            $this->hypervisor->fleet->resizeVm('pve-01', $this->derivedIdOf($job), new ResizeVmRequest(vcpu: 2, memoryMib: 4096));

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
        $this->assertSame(['web-01'], $row['reserved_provider_hostnames'] ?? null);
    }
}
