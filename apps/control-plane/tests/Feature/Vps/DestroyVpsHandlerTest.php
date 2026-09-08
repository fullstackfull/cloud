<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Application\Actions\ReleaseNodeCapacity;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\Vps\Application\Handlers\DestroyVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The end of a machine's life.
 *
 * Everything asserted here is a resource the platform was holding for ever
 * before this handler existed: an address that could never be given to
 * anybody else, capacity a node went on advertising as used, and a machine at
 * the hypervisor that no longer belonged to a paying customer.
 *
 * The ordering assertions matter more than the counting ones. Nothing is
 * released until the machine is confirmed gone, because an address handed to
 * the next customer while a live guest still answers on it is a duplicate that
 * neither of them can fix.
 */
final class DestroyVpsHandlerTest extends TestCase
{
    use RefreshDatabase;

    private const string VM_ID = '733';

    private ComputeProviderFactory $providers;

    private ComputeCluster $cluster;

    private ComputeNode $node;

    private Customer $customer;

    private Service $service;

    private VirtualMachine $machine;

    private IpAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providers = app(ComputeProviderFactory::class);

        $this->cluster = ComputeCluster::factory()->create(['status' => 'active']);
        $this->node = ComputeNode::factory()->withCapacity(16, 32_768, 1_000)->create([
            'cluster_id' => $this->cluster->id,
            'status' => NodeStatus::Active,
        ]);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $this->service = Service::factory()->active()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
        ]);

        $this->machine = VirtualMachine::factory()
            ->onNode($this->node, (int) self::VM_ID)
            ->forService($this->service)
            ->resources(2, 4096, 40)
            ->create(['hostname' => 'web-kw-09', 'storage_name' => 'local-lvm']);

        $subnet = Subnet::factory()->create(['prefix_length' => 24, 'gateway' => '192.0.2.1']);

        $address = IpAddress::factory()->create([
            'subnet_id' => $subnet->id,
            'address' => '192.0.2.77',
            'status' => IpAddressStatus::Assigned,
        ]);

        $this->assignment = IpAssignment::factory()->create([
            'ip_address_id' => $address->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->getKey(),
            'assignable_type' => VirtualMachine::class,
            'assignable_id' => $this->machine->getKey(),
            'is_primary' => true,
            'assigned_at' => now(),
            'released_at' => null,
        ]);

        NodeCapacityReservation::query()->create([
            'node_id' => $this->node->getKey(),
            'service_id' => $this->service->getKey(),
            'customer_id' => $this->customer->id,
            'reservation_key' => 'build:'.$this->service->getKey(),
            'vcpu' => 2,
            'memory_mib' => 4096,
            'disk_gib' => 40,
        ]);

        $this->node->forceFill([
            'allocated_cpu_cores' => 2,
            'allocated_memory_mib' => 4096,
            'allocated_storage_gib' => 40,
        ])->save();

        $this->provider()->createVirtualMachine(new CreateVmRequest(
            nodeName: $this->node->provider_name,
            vmId: (int) self::VM_ID,
            hostname: 'web-kw-09',
            vcpu: 2,
            memoryMib: 4096,
            diskGib: 40,
            storageName: 'local-lvm',
            startAfterCreate: true,
        ));
    }

    #[Test]
    public function the_machine_is_destroyed_and_everything_it_held_comes_back(): void
    {
        $result = $this->destroy();

        $this->assertTrue($result->successful);

        // Gone at the hypervisor.
        $this->assertNull($this->provider()->getVm($this->node->provider_name, self::VM_ID));

        // Gone from the platform's own records.
        $this->assertNull(VirtualMachine::query()->find($this->machine->getKey()));

        // The address is out of service, and specifically not back in the pool:
        // for days it still arrives at its old destination, and the next
        // customer to get it would inherit somebody else's reputation.
        $this->assertNotNull($this->assignment->refresh()->released_at);
        $this->assertSame(
            IpAddressStatus::Quarantined,
            IpAddress::query()->findOrFail($this->assignment->ip_address_id)->status,
        );

        // And the node advertises the room again.
        $node = $this->node->refresh();
        $this->assertSame(0, $node->allocated_cpu_cores);
        $this->assertSame(0, $node->allocated_memory_mib);
    }

    #[Test]
    public function a_machine_the_hypervisor_no_longer_has_is_still_a_success(): void
    {
        /*
         * The common way to arrive here twice: a redelivered message, or an
         * operator who removed the machine by hand. The provider has nothing
         * to do and the releases are the work that is left — the address and
         * the capacity are still held by the platform, which is the part that
         * matters.
         */
        $this->provider()->destroyVm($this->node->provider_name, self::VM_ID);

        $result = $this->destroy();

        $this->assertTrue($result->successful);
        $this->assertNotNull($this->assignment->refresh()->released_at);
        $this->assertSame(0, $this->node->refresh()->allocated_cpu_cores);
    }

    #[Test]
    public function a_redelivered_destroy_releases_nothing_twice(): void
    {
        $this->destroy();

        $node = $this->node->refresh();

        $second = $this->destroy();

        $this->assertTrue($second->successful);

        // The counters did not go negative and the address did not come out of
        // quarantine: both releases are keyed and idempotent.
        $this->assertSame($node->allocated_cpu_cores, $this->node->refresh()->allocated_cpu_cores);
        $this->assertSame(
            IpAddressStatus::Quarantined,
            IpAddress::query()->findOrFail($this->assignment->ip_address_id)->status,
        );
    }

    #[Test]
    public function a_hypervisor_that_stops_answering_releases_nothing(): void
    {
        /*
         * The assertion this handler exists to make safe. The platform does
         * not know whether the machine is gone; an address released here may
         * still be configured on a running guest, and the next customer to be
         * given it inherits a duplicate nobody can debug.
         */
        // The same machine, recreated under a name the fake refuses to
        // destroy conclusively.
        $this->provider()->destroyVm($this->node->provider_name, self::VM_ID);
        $this->provider()->createVirtualMachine(new CreateVmRequest(
            nodeName: $this->node->provider_name,
            vmId: (int) self::VM_ID,
            hostname: FakeComputeProvider::failingHostname('web-kw-09', FakeComputeProvider::UNDESTROYABLE_MARKER),
            vcpu: 2,
            memoryMib: 4096,
            diskGib: 40,
            storageName: 'local-lvm',
            startAfterCreate: true,
        ));

        $result = $this->destroy();

        $this->assertFalse($result->successful);
        $this->assertSame(FailureClass::Timeout, $result->failureClass);

        $this->assertNull($this->assignment->refresh()->released_at);
        $this->assertSame(2, $this->node->refresh()->allocated_cpu_cores);
        $this->assertNotNull(VirtualMachine::query()->find($this->machine->getKey()));
    }

    private function destroy(): ProvisioningResult
    {
        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::query()->create([
            'kind' => ProvisioningJobKind::DestroyVps,
            'status' => ProvisioningJobStatus::Running,
            'provider' => 'fake',
            'service_id' => $this->service->getKey(),
            'customer_id' => $this->customer->getKey(),
            'idempotency_key' => 'destroy-test-'.uniqid(),
            'payload' => ['virtual_machine_id' => (string) $this->machine->getKey()],
            'attempts' => 1,
            'max_attempts' => 2,
        ]);

        // Built with this test's factory, not resolved: the container hands
        // out a new factory — and therefore a new fake hypervisor with no
        // machines on it — to every caller.
        return (new DestroyVpsHandler(
            $this->providers,
            app(IpAllocator::class),
            app(ReleaseNodeCapacity::class),
            app(SecretRedactor::class),
        ))->execute($job);
    }

    private function provider(): ComputeProvider
    {
        return $this->providers->for($this->cluster);
    }
}
