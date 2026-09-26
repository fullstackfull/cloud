<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
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
    public function a_stranger_elsewhere_carrying_this_builds_name_is_never_delivered(): void
    {
        /*
         * F-15's safety, on the new road: a machine at the id on another node
         * named and shaped as this build names and shapes its machine. It may
         * be a stranger. The platform does not tell the two apart by name and
         * shape (the handler's second residual), so it does what it does on
         * the build's own node: builds nothing, activates nothing, and leaves
         * the machine for a person to confirm. It is not repointed around
         * either — it might be this build's, moved.
         */
        $job = $this->createJob();
        $taken = $this->derivedIdOf($job);
        $this->aMachineShapedAsThisBuildAt($taken, 'web-01', node: 'pve-02');

        $this->runWorker($job);

        $job->refresh();
        $this->assertNotSame(ProvisioningJobStatus::Succeeded, $job->status);
        $this->assertNotSame(ProvisioningJobStatus::Queued, $job->status, 'refused into a loop of retries');
        $this->assertContains($job->result['error']['code'] ?? null, [CreateVpsHandler::FOUND_ITS_OWN_BUILD, CreateVpsHandler::IDENTITY_TAKEN]);
        $this->assertSame('pve-02', $job->result['response']['node'] ?? null);
        $this->assertSame(0, VirtualMachine::query()->count(), 'a machine this build did not build was recorded as the customer\'s');
        $this->assertNotSame(ServiceStatus::Active, Service::query()->findOrFail($job->service_id)->status);
        $this->assertCount(1, $this->machinesNamed('web-01'), 'a second machine was built');
        $this->assertSame(1, count($this->hypervisor->creates), 'the create was sent again');

        $this->repointAsOperator($job)->assertStatus(409);
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
