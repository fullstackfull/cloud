<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\ValueObjects\ProvisioningResult;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\Vps\Application\Handlers\ResizeVpsHandler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The half of a plan change that happens at the hypervisor.
 *
 * `resize` was a job kind nothing created and nothing handled — a documented
 * gap, honestly reported, and the reason plan changes were not offered to
 * customers at all. With the change-plan screen it becomes the work that has
 * to finish before an upgrade is real, so the assertions here are about the
 * two ways it could quietly not: growing the disk twice, and reporting a size
 * the machine does not have.
 */
final class ResizeVpsHandlerTest extends TestCase
{
    use RefreshDatabase;

    private const string VM_ID = '910';

    private ComputeProviderFactory $providers;

    private ComputeCluster $cluster;

    private ComputeNode $node;

    private Service $service;

    private VirtualMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providers = app(ComputeProviderFactory::class);

        $this->cluster = ComputeCluster::factory()->create(['status' => 'active']);
        $this->node = ComputeNode::factory()->withCapacity(32, 65_536, 2_000)->create([
            'cluster_id' => $this->cluster->getKey(),
            'status' => NodeStatus::Active,
        ]);

        $this->service = Service::factory()->active()->create(['kind' => 'vps']);

        $this->machine = VirtualMachine::factory()
            ->onNode($this->node, (int) self::VM_ID)
            ->forService($this->service)
            ->create(['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40]);

        $this->provider()->createVirtualMachine(new CreateVmRequest(
            nodeName: $this->node->provider_name,
            vmId: (int) self::VM_ID,
            hostname: 'web-kw-01',
            vcpu: 2,
            memoryMib: 4096,
            diskGib: 40,
            storageName: 'local-lvm',
        ));
    }

    #[Test]
    public function a_growth_reaches_the_hypervisor_and_is_read_back(): void
    {
        $result = $this->resize(['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80]);

        $this->assertTrue($result->successful, (string) $result->errorMessage);

        $state = $this->provider()->getVm($this->node->provider_name, self::VM_ID);

        $this->assertSame(4, $state?->vcpu);
        $this->assertSame(8192, $state->memoryMib);
        $this->assertSame(80, $state->diskGib);

        // And the platform's record follows the hypervisor rather than the
        // request: a shape that is wrong is capacity accounting that has
        // drifted, and the next customer placed on this node is placed on a lie.
        $machine = $this->machine->fresh();
        $this->assertSame(4, $machine?->vcpu);
        $this->assertSame(80, $machine->disk_gib);
    }

    #[Test]
    public function the_disk_is_grown_by_the_difference_not_set_to_the_target(): void
    {
        /*
         * The provider contract takes a growth, and the Proxmox adapter's "+"
         * prefix depends on it. A handler that passed the absolute target
         * would ask a 40 GiB disk to grow by 80 — and on a retry would ask
         * again, leaving a machine with a disk nobody sold.
         */
        $this->resize(['disk_gib' => 60]);

        $this->assertSame(60, $this->provider()->getVm($this->node->provider_name, self::VM_ID)?->diskGib);

        // Run the same job again, as a redelivery would.
        $this->resize(['disk_gib' => 60]);

        $this->assertSame(
            60,
            $this->provider()->getVm($this->node->provider_name, self::VM_ID)?->diskGib,
            'A repeated resize grew the disk a second time.',
        );
    }

    #[Test]
    public function a_shrink_is_refused_next_to_the_provider_call(): void
    {
        /*
         * The quote refuses this before a customer can choose it. This is the
         * second check, and it is the one that runs against a payload — which
         * may have been written by an older version of the quote, an operator
         * tool, or a replay of something that was legal when it was queued.
         */
        $result = $this->resize(['disk_gib' => 20]);

        $this->assertFalse($result->successful);
        $this->assertSame('vps.disk_shrink_refused', $result->errorCode);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);

        $this->assertSame(40, $this->provider()->getVm($this->node->provider_name, self::VM_ID)?->diskGib);
    }

    #[Test]
    public function a_machine_that_is_already_the_right_size_is_a_success(): void
    {
        // A retry after a resize that worked, or a plan change that only moved
        // the price. The state the caller wanted is the state that exists.
        $result = $this->resize(['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40]);

        $this->assertTrue($result->successful);
        $this->assertTrue($result->metadata['already_correct'] ?? false);
    }

    #[Test]
    public function a_machine_that_no_longer_exists_is_a_permanent_failure(): void
    {
        $this->machine->delete();

        $result = $this->resize(['vcpu' => 4]);

        $this->assertFalse($result->successful);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function resize(array $target): ProvisioningResult
    {
        $handler = new ResizeVpsHandler($this->providers, app(SecretRedactor::class));

        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::Resize->value,
            'status' => 'running',
            'provider' => 'fake',
            'service_id' => $this->service->getKey(),
            'payload' => [
                'virtual_machine_id' => (string) $this->machine->getKey(),
                ...$target,
            ],
        ]);

        return $handler->execute($job);
    }

    private function provider(): ComputeProvider
    {
        return $this->providers->for($this->cluster);
    }
}
