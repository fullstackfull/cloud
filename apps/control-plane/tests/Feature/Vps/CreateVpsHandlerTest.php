<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The handler that turns a paid order into a running machine.
 *
 * Its ordering is the design: everything that can fail cheaply happens before
 * the one step that cannot be undone. Place, reserve capacity, reserve an
 * address — all database rows that compensation can take back — and only then
 * create the machine. Creating first would produce a machine with no address
 * and no accounting, which is worse than no machine at all: invisible to
 * billing, to monitoring and to the destroy path.
 */
final class CreateVpsHandlerTest extends TestCase
{
    use RefreshDatabase;

    private ComputeCluster $cluster;

    private ComputeNode $node;

    private IpPool $pool;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

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

        $subnet = Subnet::factory()->forBlock('198.51.100.8/29', gateway: '198.51.100.9')->create();
        app(SeedSubnetAddresses::class)->execute($subnet);
        $this->pool = $subnet->ipPool;

        $this->customer = Customer::factory()->create();
    }

    private function job(array $overrides = []): ProvisioningJob
    {
        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
        ]);

        return ProvisioningJob::factory()->create(array_merge([
            'service_id' => $service->id,
            'customer_id' => $this->customer->id,
            'kind' => 'create_vps',
            'provider' => 'fake',
            'status' => 'running',
            'payload' => [
                'cluster_id' => $this->cluster->id,
                'ip_pool_id' => $this->pool->id,
                'vcpu' => 2,
                'memory_mib' => 4096,
                'disk_gib' => 40,
                'hostname' => 'web-01',
                'os_family' => 'debian',
                'ssh_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 customer@example.com'],
            ],
        ], $overrides));
    }

    #[Test]
    public function it_places_reserves_creates_and_records_the_machine(): void
    {
        $result = app(CreateVpsHandler::class)->execute($this->job());

        $this->assertTrue($result->successful);

        $vm = VirtualMachine::query()->sole();
        $this->assertSame('web-01', $vm->hostname);
        $this->assertSame($this->node->id, $vm->node_id);
        $this->assertSame(2, $vm->vcpu);
        $this->assertSame(4096, (int) $vm->memory_mib);

        // The provider's own task id is what makes a timeout recoverable, so it
        // has to come back on the result.
        $this->assertNotNull($result->remoteJobId);
        $this->assertNotNull($result->providerReference);
    }

    #[Test]
    public function the_machine_gets_its_address_committed_not_merely_reserved(): void
    {
        app(CreateVpsHandler::class)->execute($this->job());

        $assignment = IpAssignment::query()->sole();
        $address = $assignment->ipAddress()->firstOrFail();

        $this->assertSame(IpAddressStatus::Assigned, $address->status);
        $this->assertNull($assignment->released_at);

        // Committed against a machine that exists. Committing before creation
        // would leave an assignment pointing at nothing when creation failed.
        $this->assertSame(VirtualMachine::query()->sole()->id, $assignment->assignable_id);
    }

    #[Test]
    public function node_capacity_is_committed_for_the_machine(): void
    {
        app(CreateVpsHandler::class)->execute($this->job());

        $node = $this->node->fresh();
        $this->assertSame(2, $node->allocated_cpu_cores);
        $this->assertSame(4096, (int) $node->allocated_memory_mib);
        $this->assertSame(1, $node->vm_count);
    }

    #[Test]
    public function a_retried_job_does_not_commit_capacity_twice(): void
    {
        $job = $this->job();

        app(CreateVpsHandler::class)->execute($job);
        app(CreateVpsHandler::class)->execute($job);

        // Capacity is keyed on the job's idempotency key. Without that, the
        // second commitment never comes back — release is driven by destroying
        // a machine, and there is only one machine to destroy.
        $this->assertSame(2, $this->node->fresh()->allocated_cpu_cores);
    }

    #[Test]
    public function an_exhausted_address_pool_is_a_capacity_failure_not_a_permanent_one(): void
    {
        // Take every usable address in the /29 first.
        for ($i = 0; $i < 5; $i++) {
            app(CreateVpsHandler::class)->execute($this->job());
        }

        $result = app(CreateVpsHandler::class)->execute($this->job());

        $this->assertTrue($result->isFailure());

        /*
         * Capacity, not permanent. Addresses come back — quarantine expires,
         * services terminate — so the job waits and retries rather than
         * refunding a customer who would happily have waited an hour.
         */
        $this->assertSame(FailureClass::Capacity, $result->failureClass);
    }

    #[Test]
    public function a_cluster_with_no_schedulable_node_is_also_a_capacity_failure(): void
    {
        $this->node->forceFill(['status' => 'maintenance'])->save();

        $result = app(CreateVpsHandler::class)->execute($this->job());

        $this->assertTrue($result->isFailure());
        $this->assertSame(FailureClass::Capacity, $result->failureClass);

        // Nothing was built and nothing was taken.
        $this->assertSame(0, VirtualMachine::query()->count());
        $this->assertSame(0, IpAssignment::query()->count());
    }

    #[Test]
    public function a_failed_placement_leaves_no_address_reserved(): void
    {
        $this->node->forceFill(['status' => 'offline'])->save();

        app(CreateVpsHandler::class)->execute($this->job());

        // Placement happens before any reservation precisely so that the
        // cheapest failure costs nothing to unwind.
        $reserved = $this->pool->subnets()->first()->addresses()
            ->where('status', IpAddressStatus::Reserved->value)->count();

        $this->assertSame(0, $reserved);
    }

    #[Test]
    public function the_result_carries_enough_context_to_explain_the_placement(): void
    {
        $result = app(CreateVpsHandler::class)->execute($this->job());

        // "Why did it land there" has to be answerable months later, from the
        // job row, without re-running the scheduler against a fleet that has
        // since changed.
        $this->assertSame('pve-01', $result->metadata['node']);
        $this->assertArrayHasKey('placement_score', $result->metadata);
        $this->assertArrayHasKey('primary_ipv4', $result->metadata);
    }

    #[Test]
    public function no_secret_from_the_payload_reaches_the_result_metadata(): void
    {
        $result = app(CreateVpsHandler::class)->execute($this->job([
            'payload' => [
                'cluster_id' => $this->cluster->id,
                'ip_pool_id' => $this->pool->id,
                'vcpu' => 1,
                'memory_mib' => 1024,
                'disk_gib' => 10,
                'hostname' => 'web-02',
                'root_password' => 'a-very-distinctive-secret',
            ],
        ]));

        $encoded = json_encode($result->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('a-very-distinctive-secret', $encoded);
    }
}
