<?php

declare(strict_types=1);

namespace Tests\Feature\Vps\Concerns;

use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use ReflectionMethod;
use RuntimeException;
use Tests\Feature\Vps\Doubles\AnswerLosingComputeProvider;
use Tests\TestCase;

/**
 * One cluster, one hypervisor that outlives every attempt, and the operator's
 * own routes — the setting F-15's tests all need.
 *
 * The hypervisor is shared across attempts on purpose. A fresh simulator per
 * attempt forgets what the previous attempt built, which is exactly the
 * property under test; a real cluster does not forget.
 *
 * @mixin TestCase
 */
trait DrivesVpsCreatesThroughTheOperatorPath
{
    protected ComputeCluster $cluster;

    protected ComputeNode $node;

    protected IpPool $pool;

    protected Customer $customer;

    protected AnswerLosingComputeProvider $hypervisor;

    protected function setUpTheCluster(): void
    {
        Queue::fake();

        $this->cluster = ComputeCluster::factory()->create(['driver' => 'fake']);
        $this->node = $this->addNode('pve-01');

        $network = Network::factory()->create(['bridge' => 'vmbr1', 'vlan_id' => 1234]);

        $subnet = Subnet::factory()
            ->forBlock('198.51.100.8/29', gateway: '198.51.100.9')
            ->create(['network_id' => $network->getKey()]);
        app(SeedSubnetAddresses::class)->execute($subnet);
        $this->pool = $subnet->ipPool;

        $this->customer = Customer::factory()->create();

        $this->hypervisor = new AnswerLosingComputeProvider;
        $factory = app(ComputeProviderFactory::class);
        $factory->swap($this->cluster, $this->hypervisor);
        $this->app->instance(ComputeProviderFactory::class, $factory);
    }

    protected function addNode(string $name): ComputeNode
    {
        $node = ComputeNode::factory()->create([
            'cluster_id' => $this->cluster->id,
            'provider_name' => $name,
            'cpu_cores' => 32,
            'memory_mib' => 131072,
            'storage_gib' => 2048,
        ]);

        ComputeStorage::factory()->onNode($node)->create([
            'provider_name' => 'local-nvme',
            'storage_class' => StorageClass::Nvme,
            'total_gib' => 2048,
            'available_gib' => 2048,
        ]);

        return $node;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attributes
     */
    protected function createJob(array $payload = [], array $attributes = []): ProvisioningJob
    {
        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
            'status' => ServiceStatus::Provisioning,
        ]);

        return ProvisioningJob::factory()->create([
            'service_id' => $service->id,
            'customer_id' => $this->customer->id,
            'kind' => ProvisioningJobKind::CreateVps,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Queued,
            'payload' => array_merge([
                'cluster_id' => $this->cluster->id,
                'ip_pool_id' => $this->pool->id,
                'vcpu' => 2,
                'memory_mib' => 4096,
                'disk_gib' => 40,
                'hostname' => 'web-01',
                'template_reference' => 'local:import/debian-13-genericcloud-amd64.qcow2',
                'os_family' => 'debian',
                'architecture' => 'x86_64',
                'ssh_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 customer@example.com'],
            ], $payload),
            ...$attributes,
        ]);
    }

    /**
     * One attempt, exactly as a queue worker runs it.
     */
    protected function runWorker(ProvisioningJob $job): void
    {
        $this->app->call([new RunProvisioningJob((string) $job->getKey()), 'handle']);
    }

    /**
     * One attempt whose worker is killed after the cluster has accepted the
     * create and before anything is written down about it — no task id, no
     * machine row, no settle.
     *
     * The only reflection in these tests, on `claim()`, to model exactly the
     * worker `RunProvisioningJob`'s own docblock names: it takes the job, the
     * handler builds, and the process dies. The job is left running with the
     * attempt counted and nothing settled — the state `DetectStaleJobs` exists
     * to find.
     */
    protected function runWorkerThatDiesAfterBuilding(ProvisioningJob $job): void
    {
        $engine = new RunProvisioningJob((string) $job->getKey());
        $claim = new ReflectionMethod($engine, 'claim');

        /** @var ProvisioningJob $claimed */
        $claimed = $claim->invoke($engine, app(ProvisioningJobStateMachine::class));

        $death = new RuntimeException('the worker was killed');
        $this->hypervisor->dieAfterBuilding = $death;

        try {
            app(CreateVpsHandler::class)->execute($claimed);
            $this->fail('The attempt did not reach the provider, so there was nothing for the worker to die after.');
        } catch (RuntimeException $e) {
            $this->assertSame($death, $e, 'The attempt failed for another reason: '.$e->getMessage());
        } finally {
            $this->hypervisor->dieAfterBuilding = null;
        }
    }

    protected function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }

    protected function retryAsOperator(ProvisioningJob $job): TestResponse
    {
        return $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/retry', [
                'evidence' => 'Looked at the cluster: the create did not finish, trying it once more.',
            ]);
    }

    protected function repointAsOperator(ProvisioningJob $job): TestResponse
    {
        return $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/repoint', [
                'evidence' => 'The machine at that id belongs to another customer; checked its config at the node.',
            ]);
    }

    protected function adoptAsOperator(ProvisioningJob $job, string $reference): TestResponse
    {
        return $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/adopt', [
                'provider_reference' => $reference,
                'evidence' => 'Machine found at the node under the reserved identity, named as ordered.',
            ]);
    }

    /**
     * Put somebody else's machine at an id, on a node, before this job asks.
     */
    protected function aStrangerAt(string $providerId, string $name = 'someone-elses-box', string $node = 'pve-01'): void
    {
        $this->hypervisor->fleet->createVirtualMachine(new CreateVmRequest(
            nodeName: $node,
            vmId: (int) $providerId,
            hostname: $name,
            vcpu: 8,
            memoryMib: 16384,
            diskGib: 160,
            storageName: 'local-nvme',
        ));
    }

    /**
     * Put a machine at an id that nothing about it contradicts: named as
     * given, and with the vCPU and memory every job here is created with.
     */
    protected function aMachineShapedAsThisBuildAt(string $providerId, string $name, string $node = 'pve-01'): void
    {
        $this->aStrangerAt($providerId, name: $name, node: $node);
        $this->hypervisor->fleet->resizeVm($node, $providerId, new ResizeVmRequest(vcpu: 2, memoryMib: 4096));
    }

    /**
     * The id this job will ask for before it has reserved one.
     */
    protected function derivedIdOf(ProvisioningJob $job): string
    {
        return (string) CreateVpsHandler::derivedId($job->idempotency_key);
    }

    /**
     * @return list<RemoteVmState>
     */
    protected function machinesNamed(?string $name): array
    {
        return array_values(array_filter(
            $this->hypervisor->everyMachine(),
            static fn (RemoteVmState $machine): bool => ($machine->raw['requested_hostname'] ?? $machine->name) === $name,
        ));
    }
}
