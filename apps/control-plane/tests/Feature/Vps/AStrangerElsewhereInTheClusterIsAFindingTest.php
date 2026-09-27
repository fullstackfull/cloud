<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * A stranger at this build's derived id, on a node this build was never
 * placed on.
 *
 * A Proxmox VMID names one guest in the whole cluster, and the simulator
 * refuses a create at an occupied id the same way, whichever node holds it.
 * The create looked under its identity only on the nodes its attempts were
 * placed on, so a stranger on any other node — one in maintenance, say, where
 * placement never goes — was invisible to it: every attempt was refused at
 * the id, each refusal read as transient, and the job ran out its attempts in
 * review with a finding (`compute.provider_request_failed`) the repoint does
 * not accept. A dead end, where CreateVpsHandler::vmIdFor() promises a finding
 * an operator can act on.
 *
 * Now a refused create looks for the identity on every node the platform has
 * on record for the cluster, and judges what it finds exactly as it judges a
 * machine on its own node — so a stranger by name licenses the repoint, and a
 * machine it cannot tell from its own build is never delivered to anyone.
 */
final class AStrangerElsewhereInTheClusterIsAFindingTest extends TestCase
{
    use DrivesVpsCreatesThroughTheOperatorPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->setUpTheCluster();
        $this->hypervisor->loseTheAnswerToCreates = false;
        $this->addNode('pve-02')->update(['status' => NodeStatus::Maintenance]);
    }

    #[Test]
    public function a_stranger_on_a_node_in_maintenance_is_repointed_around_and_the_build_succeeds(): void
    {
        $job = $this->createJob();
        $taken = $this->derivedIdOf($job);
        $this->aStrangerAt($taken, node: 'pve-02');

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null, (string) $job->last_error);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);
        $this->assertSame('pve-02', $job->result['response']['node'] ?? null);
        $this->assertSame(['pve-01'], $job->reserved_provider_nodes, 'placed on the maintenance node');
        $this->assertSame(1, $job->attempts, 'the stranger was a finding only after the retries ran out');

        $response = $this->repointAsOperator($job)->assertOk();
        $moved = (string) $response->json('data.reserved_provider_id');
        $this->assertNotSame($taken, $moved);

        $this->retryAsOperator($job)->assertOk();
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status, (string) $job->last_error);
        $this->assertCount(1, $this->machinesNamed('web-01'));
        $this->assertSame($moved, $this->machinesNamed('web-01')[0]->providerId);
        $this->assertSame('pve-01', $this->machinesNamed('web-01')[0]->nodeName);

        $stranger = $this->machinesNamed('someone-elses-box');
        $this->assertCount(1, $stranger, 'the stranger was touched');
        $this->assertSame([$taken, 'pve-02'], [$stranger[0]->providerId, $stranger[0]->nodeName]);
    }

    #[Test]
    public function a_stranger_elsewhere_carrying_this_builds_name_on_a_first_attempt_is_repointed_around(): void
    {
        /*
         * A machine at the id on another node, named and shaped as this build
         * names and shapes its machine, there before the build's first
         * attempt. The create is refused because of it. The name the refused
         * create carried was recorded just before it was sent — and is no
         * evidence of ownership: that create built nothing, and no create
         * under the identity was ever sent to that node. It used to be taken
         * for this build's own (`found_its_own_build`): repoint and retry
         * refused, adoption accepted, and a customer's service active on
         * somebody else's machine. Judged against the names sent before the
         * refused create — none — it is a stranger by name.
         */
        $job = $this->createJob();
        $taken = $this->derivedIdOf($job);
        $this->aMachineShapedAsThisBuildAt($taken, 'web-01', node: 'pve-02');

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::IDENTITY_TAKEN, $job->result['error']['code'] ?? null, (string) $job->last_error);
        $this->assertSame(CreateVpsHandler::REASON_NAMED_OTHERWISE, $job->result['error']['reason'] ?? null);
        $this->assertSame('pve-02', $job->result['response']['node'] ?? null);
        $this->assertSame([], $job->result['response']['called_names'] ?? null);
        $this->assertTrue($job->result['response']['judged_after_its_own_refusal'] ?? null);
        $this->assertNull($job->result['provider_reference'] ?? null, 'the stranger was carried as this build\'s provider reference');
        $this->assertSame(0, VirtualMachine::query()->count());

        $moved = (string) $this->repointAsOperator($job)->assertOk()->json('data.reserved_provider_id');
        $this->retryAsOperator($job)->assertOk();
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->status, (string) $job->last_error);
        $this->assertSame([$moved], VirtualMachine::query()->pluck('provider_id')->all(), 'the customer was given a machine at the stranger\'s id');

        $atTheTakenId = array_values(array_filter(
            $this->hypervisor->everyMachine(),
            static fn ($machine): bool => $machine->providerId === $taken,
        ));
        $this->assertCount(1, $atTheTakenId);
        $this->assertSame('pve-02', $atTheTakenId[0]->nodeName, 'the stranger was touched');
    }

    #[Test]
    public function a_machine_elsewhere_carrying_a_name_an_earlier_attempt_sent_is_never_delivered_without_adoption(): void
    {
        /*
         * The case the name still counts in: an earlier attempt of this build
         * sent a create with the name, and its outcome was never learned. A
         * machine carrying that name and the plan's shape on another node may
         * be that create's, moved — so it is not repointed around, and
         * nothing is recorded or activated until a person adopts it.
         */
        $job = $this->createJob();
        $taken = $this->derivedIdOf($job);

        $this->hypervisor->loseTheRequestToCreates = true;
        $this->runWorker($job);
        $this->hypervisor->loseTheRequestToCreates = false;

        $this->assertSame(['web-01'], $job->refresh()->namesACreateWasSentWith());
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);

        $this->aMachineShapedAsThisBuildAt($taken, 'web-01', node: 'pve-02');

        $this->retryAsOperator($job)->assertOk();
        DB::table('provisioning_jobs')->where('id', $job->id)->update(['next_attempt_at' => null]);
        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null, (string) $job->last_error);
        $this->assertSame('pve-02', $job->result['response']['node'] ?? null);
        $this->assertNotSame(ProvisioningJobStatus::Succeeded, $job->status);
        $this->assertSame(0, VirtualMachine::query()->count(), 'a machine was recorded as the customer\'s without an adoption');
        $this->assertNotSame(ServiceStatus::Active, Service::query()->findOrFail($job->service_id)->status);
        $this->assertCount(1, $this->machinesNamed('web-01'), 'a second machine was built');

        $this->repointAsOperator($job)->assertStatus(409);
    }

    #[Test]
    public function a_machine_on_a_node_this_build_was_placed_on_is_judged_by_every_name_sent(): void
    {
        /*
         * The other side of the rule. Another worker holding this job at the
         * same time records the node, then the name, then sends — and its
         * create lands at the id on the node this attempt was placed on just
         * before this attempt's own create is refused because of it. That
         * machine carries the recorded name and may well be this build's; it
         * must not be read as a stranger (and repointed around into a second
         * machine) just because this attempt's own send was refused.
         */
        $job = $this->createJob();
        $taken = $this->derivedIdOf($job);

        $this->hypervisor->atTheMomentOfCreate = function () use ($taken): void {
            $this->hypervisor->atTheMomentOfCreate = null;
            $this->aMachineShapedAsThisBuildAt($taken, 'web-01', node: 'pve-01');
        };

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(CreateVpsHandler::FOUND_ITS_OWN_BUILD, $job->result['error']['code'] ?? null, (string) $job->last_error);
        $this->assertFalse($job->result['response']['judged_after_its_own_refusal'] ?? null);
        $this->assertSame(0, VirtualMachine::query()->count());
        $this->repointAsOperator($job)->assertStatus(409);
    }

    #[Test]
    public function a_node_of_another_cluster_is_not_asked(): void
    {
        /*
         * The inventory looked at is the identity's cluster's. The simulator's
         * fleet is one id space across every node it holds, so a machine at
         * the id on a node of ANOTHER cluster still refuses this create; a
         * look that read every node row would find it and report a cluster
         * this build was never in.
         */
        $other = ComputeCluster::factory()->create(['driver' => 'fake']);
        ComputeNode::factory()->create(['cluster_id' => $other->id, 'provider_name' => 'pve-other']);

        $job = $this->createJob();
        $this->aStrangerAt($this->derivedIdOf($job), node: 'pve-other');

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame('compute.provider_request_failed', $job->result['error']['code'] ?? null);
        $this->assertNotSame('pve-other', $job->result['response']['node'] ?? null);
    }

    #[Test]
    public function a_refusal_with_nothing_at_the_id_anywhere_stays_a_transient_refusal(): void
    {
        $job = $this->createJob();
        $this->hypervisor->atTheMomentOfCreate = static function (): void {
            throw ComputeProviderException::requestFailed('fake', 'create_vm', ['provider_message' => 'storage busy']);
        };

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status);
        $this->assertSame('compute.provider_request_failed', $job->result['error']['code'] ?? null);
    }

    #[Test]
    public function a_node_that_cannot_be_asked_leaves_the_refusal_as_it_was(): void
    {
        $job = $this->createJob();
        $this->aStrangerAt($this->derivedIdOf($job), node: 'pve-02');

        // Every look succeeds until the create is refused; after that no node
        // answers, as a node that is down does not.
        $this->hypervisor->atTheMomentOfCreate = function (): void {
            $this->hypervisor->failReadsWith = ComputeProviderException::requestFailed('fake', 'get_vm', ['provider_message' => 'node unreachable']);
        };

        $this->runWorker($job);

        $job->refresh();
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status, (string) $job->last_error);
        $this->assertSame('compute.provider_request_failed', $job->result['error']['code'] ?? null);
    }
}
