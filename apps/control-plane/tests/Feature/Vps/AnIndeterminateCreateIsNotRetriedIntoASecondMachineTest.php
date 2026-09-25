<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
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
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Doubles\AnswerLosingComputeProvider;
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
 */
final class AnIndeterminateCreateIsNotRetriedIntoASecondMachineTest extends TestCase
{
    use RefreshDatabase;

    private ComputeCluster $cluster;

    private ComputeNode $node;

    private IpPool $pool;

    private Customer $customer;

    private AnswerLosingComputeProvider $hypervisor;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(RolePermissionSeeder::class);

        $this->cluster = ComputeCluster::factory()->create(['driver' => 'fake']);

        $this->node = ComputeNode::factory()->create([
            'cluster_id' => $this->cluster->id,
            'provider_name' => 'pve-01',
            'cpu_cores' => 32,
            'memory_mib' => 131072,
            'storage_gib' => 2048,
        ]);

        ComputeStorage::factory()->onNode($this->node)->create([
            'provider_name' => 'local-nvme',
            'storage_class' => StorageClass::Nvme,
            'total_gib' => 2048,
            'available_gib' => 2048,
        ]);

        $network = Network::factory()->create(['bridge' => 'vmbr1', 'vlan_id' => 1234]);

        $subnet = Subnet::factory()
            ->forBlock('198.51.100.8/29', gateway: '198.51.100.9')
            ->create(['network_id' => $network->getKey()]);
        app(SeedSubnetAddresses::class)->execute($subnet);
        $this->pool = $subnet->ipPool;

        $this->customer = Customer::factory()->create();

        // One hypervisor for the whole test, so that what the first attempt
        // built is still there when the retry looks.
        $this->hypervisor = new AnswerLosingComputeProvider;
        $factory = app(ComputeProviderFactory::class);
        $factory->swap($this->cluster, $this->hypervisor);
        $this->app->instance(ComputeProviderFactory::class, $factory);
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
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createJob(array $payload = []): ProvisioningJob
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
        ]);
    }

    private function runWorker(ProvisioningJob $job): void
    {
        $this->app->call([new RunProvisioningJob((string) $job->getKey()), 'handle']);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }

    private function retryAsOperator(ProvisioningJob $job): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->operator())
            ->postJson('/api/admin/provisioning/jobs/'.$job->id.'/retry', [
                'evidence' => 'Looked at the cluster: the create timed out, trying it once more.',
            ]);
    }
}
