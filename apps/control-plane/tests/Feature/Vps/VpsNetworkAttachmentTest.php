<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ReinstallVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteTaskState;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\VmOperation;
use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Application\Actions\SeedSubnetAddresses;
use Lynomia\Modules\Ipam\Infrastructure\Models\Network;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Application\Handlers\CreateVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which layer-2 segment a customer's machine is plugged into.
 *
 * IPAM models this explicitly — a network carries a bridge, a VLAN id, a
 * purpose and a customer-facing flag, and Network::acceptsCustomerAttachments()
 * answers "may a customer VM be attached here" — and none of it protects
 * anybody unless the build passes it to the hypervisor.
 *
 * A machine created on a default bridge with no VLAN tag lands on that
 * bridge's native VLAN whatever its subnet says. On this platform's own
 * inventory the default bridge is the one carried by the node's management
 * interface, which puts a machine running customer code beside the hypervisor
 * and BMC management interfaces — the lateral movement
 * IpPoolScope::isCustomerAllocatable() exists to close, reached one layer
 * lower — and it puts every tenant in one untagged broadcast domain regardless
 * of the VLAN their subnet names.
 */
final class VpsNetworkAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private ComputeCluster $cluster;

    private ComputeNode $node;

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

        $this->customer = Customer::factory()->create();
    }

    #[Test]
    public function the_machine_is_attached_to_the_bridge_and_vlan_of_the_network_its_address_came_from(): void
    {
        $network = Network::factory()->create([
            'bridge' => 'vmbr1',
            'vlan_id' => 1234,
            'is_customer_facing' => true,
        ]);

        $recorder = $this->recordingProvider();

        $result = app(CreateVpsHandler::class)->execute($this->job($network));

        $this->assertTrue($result->successful);
        $this->assertNotNull($recorder->request);

        // Not the DTO's default. The platform's own record of where this
        // address lives is what reaches the hypervisor.
        $this->assertSame('vmbr1', $recorder->request->networkBridge);
        $this->assertSame(1234, $recorder->request->vlanTag);
    }

    #[Test]
    public function a_subnet_with_no_network_is_refused_rather_than_attached_to_a_default_bridge(): void
    {
        $recorder = $this->recordingProvider();

        $result = app(CreateVpsHandler::class)->execute($this->job(null));

        $this->assertFalse($result->successful);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame('vps.network_not_attachable', $result->errorCode);

        // And nothing was asked of the hypervisor: the refusal happens before
        // the one irreversible step.
        $this->assertNull($recorder->request);
        $this->assertSame(0, VirtualMachine::query()->count());
    }

    #[Test]
    public function a_management_network_never_takes_a_customer_machine(): void
    {
        // Both flags are set the way an operator would have to set them to get
        // a customer VM onto the management segment by accident.
        $network = Network::factory()->management()->create([
            'bridge' => 'vmbr0',
            'is_customer_facing' => true,
        ]);

        $recorder = $this->recordingProvider();

        $result = app(CreateVpsHandler::class)->execute($this->job($network));

        $this->assertFalse($result->successful);
        $this->assertSame('vps.network_not_attachable', $result->errorCode);
        $this->assertNull($recorder->request);
    }

    private function recordingProvider(): RecordingComputeProvider
    {
        $recorder = new RecordingComputeProvider;

        // The factory is not a singleton, so the instance the handler resolves
        // has to be the one the recorder was swapped into.
        $factory = app(ComputeProviderFactory::class);
        $factory->swap($this->cluster, $recorder);
        app()->instance(ComputeProviderFactory::class, $factory);

        return $recorder;
    }

    private function job(?Network $network): ProvisioningJob
    {
        $subnet = Subnet::factory()
            ->forBlock('198.51.100.8/29', gateway: '198.51.100.9')
            ->create(['network_id' => $network?->getKey()]);

        app(SeedSubnetAddresses::class)->execute($subnet);

        $service = Service::factory()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
        ]);

        return ProvisioningJob::factory()->create([
            'service_id' => $service->id,
            'customer_id' => $this->customer->id,
            'kind' => 'create_vps',
            'provider' => 'fake',
            'status' => 'running',
            'payload' => [
                'cluster_id' => $this->cluster->id,
                'ip_pool_id' => $subnet->ipPool->id,
                'vcpu' => 2,
                'memory_mib' => 4096,
                'disk_gib' => 40,
                'hostname' => 'web-01',
            ],
        ]);
    }
}

/**
 * Records the create request and otherwise does nothing.
 */
final class RecordingComputeProvider implements ComputeProvider
{
    public ?CreateVmRequest $request = null;

    public function name(): string
    {
        return 'recording';
    }

    public function createVirtualMachine(CreateVmRequest $request): VmOperation
    {
        $this->request = $request;

        return new VmOperation(
            taskId: 'task-1',
            nodeName: $request->nodeName,
            providerId: (string) $request->vmId,
            operation: 'create',
            status: RemoteTaskStatus::Succeeded,
        );
    }

    public function startVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->noop($nodeName, $providerId, 'start');
    }

    public function stopVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->noop($nodeName, $providerId, 'stop');
    }

    public function shutdownVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->noop($nodeName, $providerId, 'shutdown');
    }

    public function rebootVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->noop($nodeName, $providerId, 'reboot');
    }

    public function resetVm(string $nodeName, string $providerId): VmOperation
    {
        return $this->noop($nodeName, $providerId, 'reset');
    }

    public function resizeVm(string $nodeName, string $providerId, ResizeVmRequest $request): VmOperation
    {
        return $this->noop($nodeName, $providerId, 'resize');
    }

    public function destroyVm(string $nodeName, string $providerId, bool $purge = true): VmOperation
    {
        return $this->noop($nodeName, $providerId, 'destroy');
    }

    public function suspendVm(string $nodeName, string $providerId, SuspensionPolicy $policy): VmOperation
    {
        // Recorded like every other call, so a test asserting that a code path
        // does NOT suspend a machine has something to assert against.
        $this->calls[] = 'suspendVm';

        return new VmOperation('UPID:suspend', $nodeName, $providerId, 'suspend_vm');
    }

    public function liftSuspension(string $nodeName, string $providerId): VmOperation
    {
        $this->calls[] = 'liftSuspension';

        return new VmOperation('UPID:unsuspend', $nodeName, $providerId, 'lift_suspension');
    }

    public function reinstallVm(string $nodeName, string $providerId, ReinstallVmRequest $request): VmOperation
    {
        // Recorded rather than performed. A test asserting that some code path
        // does not rebuild a customer's machine needs the call to be visible,
        // and one asserting that it does needs it to be cheap.
        $this->calls[] = 'reinstallVm';

        return new VmOperation('UPID:reinstall', $nodeName, $providerId, 'reinstall_vm');
    }

    public function getVm(string $nodeName, string $providerId): ?RemoteVmState
    {
        return null;
    }

    public function listVms(string $nodeName): array
    {
        return [];
    }

    public function getTask(string $nodeName, string $taskId): RemoteTaskState
    {
        return new RemoteTaskState(
            taskId: $taskId,
            nodeName: $nodeName,
            status: RemoteTaskStatus::Succeeded,
        );
    }

    public function listNodes(): array
    {
        return [];
    }

    private function noop(string $nodeName, string $providerId, string $operation): VmOperation
    {
        return new VmOperation(
            taskId: 'task-noop',
            nodeName: $nodeName,
            providerId: $providerId,
            operation: $operation,
            status: RemoteTaskStatus::Succeeded,
        );
    }
}
