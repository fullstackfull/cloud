<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ReinstallVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
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
use Lynomia\Modules\Vps\Application\Handlers\ReinstallVpsHandler;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The handler that was missing.
 *
 * Until this phase POST /vps/{vm}/reinstall answered 202, wrote a job row, and
 * queued work no handler was registered for: the job died at the worker with
 * HandlerNotRegisteredException. The button existed, the confirmation dialogue
 * existed, the guards existed, and nothing rebuilt anything.
 *
 * These tests are written around the invariants rather than the steps, because
 * the steps are allowed to change and the invariants are not. A reinstall must
 * never produce a second machine for one service, never leave the service
 * mapped to nothing, never move or duplicate an address assignment, and never
 * — under any failure — retry itself onto a disk it may already be rewriting.
 */
final class ReinstallVpsHandlerTest extends TestCase
{
    use RefreshDatabase;

    private const string VM_ID = '410';

    private ComputeProviderFactory $providers;

    private ComputeCluster $cluster;

    private ComputeNode $node;

    private Customer $customer;

    private Service $service;

    private VirtualMachine $machine;

    private VmTemplate $template;

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

        $this->template = VmTemplate::factory()->create([
            'cluster_id' => $this->cluster->id,
            'provider_reference' => 'local:import/debian-13.qcow2',
            'os_family' => OsFamily::Debian,
        ]);

        $this->machine = VirtualMachine::factory()
            ->onNode($this->node, (int) self::VM_ID)
            ->forService($this->service)
            ->create([
                'hostname' => 'web-kw-01',
                'storage_name' => 'local-lvm',
                'template_id' => $this->template->getKey(),
                'power_state' => PowerState::Running,
                'disk_gib' => 40,
            ]);

        $this->giveTheMachineAnAddress();

        $this->provider()->createVirtualMachine(new CreateVmRequest(
            nodeName: $this->node->provider_name,
            vmId: (int) self::VM_ID,
            hostname: 'web-kw-01',
            vcpu: 2,
            memoryMib: 4096,
            diskGib: 40,
            storageName: 'local-lvm',
            startAfterCreate: true,
        ));
    }

    #[Test]
    public function a_reinstall_replaces_the_image_and_keeps_the_machine(): void
    {
        $newImage = VmTemplate::factory()->create([
            'cluster_id' => $this->cluster->id,
            'provider_reference' => 'local:import/rocky-10.qcow2',
            'os_family' => OsFamily::Rocky,
        ]);

        $result = $this->reinstall(['template_id' => (string) $newImage->getKey()]);

        $this->assertTrue($result->successful, $result->errorMessage ?? '');

        $state = $this->remote();

        // The same machine: same id, same node, same shape.
        $this->assertNotNull($state);
        $this->assertSame(self::VM_ID, $state->providerId);
        $this->assertSame(2, $state->vcpu);
        $this->assertSame(4096, $state->memoryMib);
        $this->assertSame(40, $state->diskGib);

        // Carrying the new image.
        $this->assertSame('local:import/rocky-10.qcow2', $state->raw['installed_template'] ?? null);

        $machine = $this->machine->fresh();
        $this->assertSame((string) $newImage->getKey(), $machine?->template_id);
        $this->assertSame(OsFamily::Rocky->value, $machine->os_family);
        $this->assertSame('web-kw-01', $machine->hostname);
    }

    #[Test]
    public function one_service_still_has_exactly_one_machine_and_one_address(): void
    {
        /*
         * The invariant the whole design exists to protect. A reinstall
         * implemented as build-a-new-one-and-destroy-the-old would satisfy
         * every other assertion in this file and break this one — and would do
         * it silently, because the customer's server would work.
         */
        $this->reinstall();

        $this->assertSame(1, VirtualMachine::query()->where('service_id', $this->service->getKey())->count());

        $live = IpAssignment::query()
            ->where('assignable_id', $this->machine->getKey())
            ->whereNull('released_at')
            ->get();

        $this->assertCount(1, $live, 'The reinstall changed how many addresses this machine holds.');
        $this->assertSame('192.0.2.40', $live->first()?->ipAddress?->address);
    }

    #[Test]
    public function the_operation_ends_completed_and_says_what_it_kept(): void
    {
        $this->reinstall();

        $operation = VmReinstall::query()->sole();

        $this->assertSame(ReinstallState::Completed, $operation->state);
        $this->assertNotNull($operation->completed_at);
        $this->assertNotNull($operation->destroyed_at);
        $this->assertSame(self::VM_ID, $operation->provider_resource_id);
        $this->assertSame($this->node->provider_name, $operation->provider_node);

        // The snapshot an operator reads to answer "is this the same machine".
        $this->assertSame('192.0.2.40', $operation->preserved['primary_address'] ?? null);
        $this->assertSame('web-kw-01', $operation->preserved['hostname'] ?? null);
        $this->assertSame(40, $operation->preserved['disk_gib'] ?? null);
        $this->assertSame('local-lvm', $operation->preserved['storage_name'] ?? null);
    }

    #[Test]
    public function an_indeterminate_reinstall_is_never_retried(): void
    {
        /*
         * The most important assertion in this file. A retried reinstall lands
         * on a machine that may be mid-rebuild and destroys whatever the first
         * attempt had laid down — so a provider call the platform stopped
         * waiting for must classify as a timeout, which the engine refuses to
         * retry and escalates to a person.
         */
        $this->machine->update(['hostname' => FakeComputeProvider::failingHostname(
            'web-kw-01',
            FakeComputeProvider::TIMEOUT_MARKER,
        )]);

        $result = $this->reinstall();

        $this->assertFalse($result->successful);
        $this->assertSame(FailureClass::Timeout, $result->failureClass);
        $this->assertFalse($result->failureClass->isAutomaticallyRetryable());
        $this->assertTrue($result->failureClass->requiresReview());

        $operation = VmReinstall::query()->sole();

        $this->assertSame(ReinstallState::Indeterminate, $operation->state);

        /*
         * And everything needed to find out what actually happened, without
         * having to ask the customer. A timeout whose record does not name the
         * machine and the node is a timeout nobody can resolve.
         */
        $this->assertSame(self::VM_ID, $operation->provider_resource_id);
        $this->assertSame($this->node->provider_name, $operation->provider_node);
        $this->assertSame((string) $this->service->getKey(), $operation->service_id);
        $this->assertTrue($operation->destroyedData());
    }

    #[Test]
    public function a_refused_reinstall_is_left_for_a_person(): void
    {
        // The provider answered, so the platform knows the call failed — but
        // not how far the adapter got. The disk may be detached.
        $this->machine->update(['hostname' => FakeComputeProvider::failingHostname('web-kw-01')]);

        $result = $this->reinstall();

        $this->assertFalse($result->successful);

        $operation = VmReinstall::query()->sole();

        $this->assertSame(ReinstallState::NeedsReview, $operation->state);
        $this->assertNotNull($operation->failure_code);
        $this->assertTrue($operation->state->needsAttention());
    }

    #[Test]
    public function a_machine_whose_storage_is_unknown_is_refused_before_anything_is_destroyed(): void
    {
        /*
         * A row written before the platform recorded where disks live. The
         * alternative to refusing is creating the replacement on a storage the
         * customer did not buy, during an operation they already know is
         * destructive.
         */
        $this->machine->update(['storage_name' => null]);

        $result = $this->reinstall();

        $this->assertFalse($result->successful);
        $this->assertSame('vps.reinstall_storage_unknown', $result->errorCode);

        $operation = VmReinstall::query()->sole();

        $this->assertSame(ReinstallState::Failed, $operation->state);
        $this->assertFalse($operation->destroyedData(), 'A refusal before the disk was touched was recorded as destructive.');
        $this->assertNull($operation->destroyed_at);

        // And the machine is untouched: the customer still has what they had.
        $this->assertSame(PowerState::Running, $this->remote()?->powerState);
    }

    #[Test]
    public function a_machine_with_no_live_address_is_refused(): void
    {
        // Rebuilding would produce a guest with no route out — which, from the
        // customer's side, is indistinguishable from a destroyed server.
        IpAssignment::query()->where('assignable_id', $this->machine->getKey())
            ->update(['released_at' => now()]);

        $result = $this->reinstall();

        $this->assertFalse($result->successful);
        $this->assertSame('vps.reinstall_address_missing', $result->errorCode);
        $this->assertSame(ReinstallState::Failed, VmReinstall::query()->sole()->state);
    }

    #[Test]
    public function an_image_that_is_not_staged_on_this_cluster_is_refused(): void
    {
        $elsewhere = VmTemplate::factory()->create([
            'cluster_id' => ComputeCluster::factory()->create()->id,
            'provider_reference' => 'local:import/somebody-elses.qcow2',
        ]);

        $result = $this->reinstall(['template_id' => (string) $elsewhere->getKey()]);

        $this->assertFalse($result->successful);
        $this->assertSame('vps.reinstall_image_unavailable', $result->errorCode);
        $this->assertFalse(VmReinstall::query()->sole()->destroyedData());
    }

    #[Test]
    public function a_retired_image_is_refused_rather_than_substituted(): void
    {
        $this->template->update(['is_active' => false]);

        $result = $this->reinstall();

        $this->assertFalse($result->successful);
        $this->assertSame('vps.reinstall_image_unavailable', $result->errorCode);
    }

    #[Test]
    public function naming_no_image_reinstalls_the_one_the_machine_already_has(): void
    {
        $result = $this->reinstall();

        $this->assertTrue($result->successful);
        $this->assertSame(
            'local:import/debian-13.qcow2',
            $this->remote()?->raw['installed_template'] ?? null,
        );
    }

    #[Test]
    public function a_suspended_machine_cannot_be_rebuilt_at_the_provider(): void
    {
        /*
         * A customer whose service was cut off for non-payment must not be
         * able to rebuild their way out of it. VpsOperationGuard refuses this
         * at the endpoint; this asserts the second lock — the hypervisor's own
         * — which is what remains when a guard is forgotten.
         */
        $this->provider()->suspendVm($this->node->provider_name, self::VM_ID, SuspensionPolicy::PowerOffAndLock);

        $result = $this->reinstall();

        $this->assertFalse($result->successful);
        $this->assertSame(ReinstallState::NeedsReview, VmReinstall::query()->sole()->state);
    }

    #[Test]
    public function a_redelivered_job_does_not_rebuild_the_machine_twice(): void
    {
        /*
         * A queue that delivers a message twice must not cost a customer their
         * data twice. The second delivery finds a finished operation and
         * answers without touching anything.
         */
        $job = $this->job();

        $this->handler()->execute($job);

        $rocky = VmTemplate::factory()->create([
            'cluster_id' => $this->cluster->id,
            'provider_reference' => 'local:import/rocky-10.qcow2',
        ]);

        // The image on the machine is changed underneath, so a second run that
        // did anything at all would be visible.
        $this->provider()->reinstallVm($this->node->provider_name, self::VM_ID, new ReinstallVmRequest(
            templateReference: (string) $rocky->provider_reference,
            storageName: 'local-lvm',
            diskGib: 40,
            hostname: 'web-kw-01',
        ));

        $second = $this->handler()->execute($job->fresh());

        $this->assertTrue($second->successful);
        $this->assertSame('local:import/rocky-10.qcow2', $this->remote()?->raw['installed_template'] ?? null);
        $this->assertCount(1, VmReinstall::query()->get());
    }

    #[Test]
    public function a_machine_that_no_longer_exists_is_a_permanent_failure(): void
    {
        $this->machine->delete();

        $result = $this->reinstall();

        $this->assertFalse($result->successful);
        $this->assertSame(FailureClass::Permanent, $result->failureClass);
        $this->assertSame('vps.unknown_machine', $result->errorCode);
    }

    private function handler(): ReinstallVpsHandler
    {
        return new ReinstallVpsHandler($this->providers, app(SecretRedactor::class));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reinstall(array $payload = []): ProvisioningResult
    {
        return $this->handler()->execute($this->job($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function job(array $payload = []): ProvisioningJob
    {
        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::query()->create([
            'kind' => ProvisioningJobKind::ReinstallVps,
            'status' => ProvisioningJobStatus::Running,
            'provider' => 'fake',
            'service_id' => $this->service->getKey(),
            'customer_id' => $this->customer->getKey(),
            'idempotency_key' => 'reinstall-test-'.uniqid(),
            'payload' => [
                'virtual_machine_id' => (string) $this->machine->getKey(),
                'hostname' => $this->machine->hostname,
                'ssh_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI test@lynomia'],
                ...$payload,
            ],
            'attempts' => 1,
            'max_attempts' => 1,
        ]);

        return $job;
    }

    private function giveTheMachineAnAddress(): void
    {
        $subnet = Subnet::factory()->create(['prefix_length' => 24, 'gateway' => '192.0.2.1']);

        $address = IpAddress::factory()->create([
            'subnet_id' => $subnet->id,
            'address' => '192.0.2.40',
        ]);

        IpAssignment::factory()->create([
            'ip_address_id' => $address->id,
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->getKey(),
            'assignable_type' => VirtualMachine::class,
            'assignable_id' => $this->machine->getKey(),
            'is_primary' => true,
            'assigned_at' => now(),
            'released_at' => null,
        ]);
    }

    private function provider(): ComputeProvider
    {
        return $this->providers->for($this->cluster);
    }

    private function remote(): ?RemoteVmState
    {
        return $this->provider()->getVm($this->node->provider_name, self::VM_ID);
    }
}
