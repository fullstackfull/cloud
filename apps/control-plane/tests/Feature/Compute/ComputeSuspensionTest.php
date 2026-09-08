<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Application\Actions\EnforceComputeSuspension;
use Lynomia\Modules\Compute\Application\Actions\LiftComputeSuspension;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Enums\SuspensionPolicy;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Suspension that a customer cannot undo.
 *
 * Phase 29 refused to implement this as "stop the VM", and the reasoning was
 * right: at the hypervisor a stop is indistinguishable from the customer
 * stopping their own machine, and nothing about a stopped VM prevents it being
 * started again. The platform's API guard is one forgotten check away from
 * nothing.
 *
 * What makes it enforcement is the rest of the policy — clearing onboot so a
 * node reboot does not resurrect it, and Proxmox's config lock, which refuses
 * every subsequent operation from any caller including somebody typing
 * `qm start` on the node itself. These tests assert that property against the
 * fake, which models the refusal faithfully for exactly this reason.
 */
final class ComputeSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private ComputeProviderFactory $providers;

    private ComputeCluster $cluster;

    private ComputeNode $node;

    private Service $service;

    private VirtualMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        // One factory for the whole test: the container deliberately does not
        // share them, so resolving twice gives assertions a fake hypervisor
        // with none of the machines the test just made.
        $this->providers = app(ComputeProviderFactory::class);

        $this->cluster = ComputeCluster::factory()->create(['status' => 'active']);
        $this->node = ComputeNode::factory()->withCapacity(16, 32_768, 1_000)->create([
            'cluster_id' => $this->cluster->id,
            'status' => NodeStatus::Active,
        ]);

        $this->service = Service::factory()->active()->create(['kind' => 'vps']);

        $this->machine = VirtualMachine::factory()->onNode($this->node, 300)->create([
            'service_id' => $this->service->getKey(),
        ]);

        $this->provider()->createVirtualMachine(new CreateVmRequest(
            nodeName: $this->node->provider_name,
            vmId: 300,
            hostname: 'web-kw-01',
            vcpu: 2,
            memoryMib: 4096,
            diskGib: 40,
            storageName: 'local-lvm',
            startAfterCreate: true,
        ));
    }

    #[Test]
    public function suspension_powers_the_machine_off_and_locks_it(): void
    {
        $this->assertTrue($this->suspend());

        $state = $this->remote();

        $this->assertNotNull($state);
        $this->assertSame(PowerState::Stopped, $state->powerState);
        $this->assertTrue($state->isLockedAtProvider());

        // Cleared, or the suspension survives only until the next time the
        // node reboots and the unpaid machine quietly comes back.
        $this->assertFalse($state->startsOnBoot);
    }

    #[Test]
    public function a_suspended_machine_cannot_be_started_at_the_provider(): void
    {
        /*
         * The property the whole design exists for. The platform's own API
         * guard is not what is being tested here — this is the hypervisor
         * refusing, which is what remains true when a guard is forgotten.
         */
        $this->suspend();

        $this->expectException(ComputeProviderException::class);

        $this->provider()->startVm($this->node->provider_name, '300');
    }

    #[Test]
    public function suspending_twice_is_not_an_error(): void
    {
        // A retried job, or a reconciliation confirming a state that is
        // already true.
        $this->assertTrue($this->suspend());
        $this->assertTrue($this->suspend());

        $this->assertTrue($this->remote()?->isLockedAtProvider());
    }

    #[Test]
    public function lifting_the_suspension_unlocks_and_starts_the_machine(): void
    {
        $this->suspend();

        $this->assertTrue($this->lift());

        $state = $this->remote();

        $this->assertNotNull($state);
        $this->assertFalse($state->isLockedAtProvider());
        $this->assertSame(PowerState::Running, $state->powerState);
        $this->assertTrue($state->startsOnBoot);
    }

    #[Test]
    public function a_machine_the_customer_had_stopped_stays_stopped_after_reactivation(): void
    {
        /*
         * A customer who had deliberately powered their server down before
         * falling behind on payment must not find it running when they pay
         * again. Once the suspension has stopped it, the provider can no
         * longer tell the two cases apart, so the state is recorded before the
         * machine is touched.
         */
        $this->provider()->stopVm($this->node->provider_name, '300');

        $this->suspend();
        $this->lift();

        $this->assertSame(PowerState::Stopped, $this->remote()?->powerState);
        $this->assertFalse($this->remote()?->isLockedAtProvider());
    }

    #[Test]
    public function lifting_leaves_somebody_elses_lock_alone(): void
    {
        /*
         * A machine locked by a running backup is not the platform's to
         * unlock. Clearing it would have Lynomia quietly interfering with an
         * operation it did not start.
         */
        $this->suspend();

        // A backup starts while the machine is suspended and takes the lock.
        $this->relock('backup');

        $this->assertFalse($this->lift(), 'A foreign lock was reported as a successful reactivation.');
        $this->assertSame('backup', $this->remote()?->lock);
    }

    #[Test]
    public function the_record_only_policy_changes_nothing_at_the_provider(): void
    {
        /*
         * What the platform did before this phase, named so that a deployment
         * which wants it has to choose it rather than getting it from an
         * omission nobody noticed.
         */
        $this->assertTrue($this->suspend(SuspensionPolicy::RecordOnly));

        $state = $this->remote();

        $this->assertSame(PowerState::Running, $state?->powerState);
        $this->assertFalse($state->isLockedAtProvider());
    }

    #[Test]
    public function the_power_off_policy_stops_without_locking(): void
    {
        $this->assertTrue($this->suspend(SuspensionPolicy::PowerOff));

        $state = $this->remote();

        $this->assertSame(PowerState::Stopped, $state?->powerState);
        $this->assertFalse($state->isLockedAtProvider());

        // And the machine can be started again from the node, which is exactly
        // what this policy is for and why it is not the default.
        $this->provider()->startVm($this->node->provider_name, '300');
        $this->assertSame(PowerState::Running, $this->remote()?->powerState);
    }

    #[Test]
    public function a_service_with_no_machine_suspends_cleanly(): void
    {
        // A service still being built, or one that is not compute at all.
        // There is nothing running for the customer to use, which is what the
        // caller is actually asking about.
        $orphan = Service::factory()->active()->create(['kind' => 'vps']);

        $this->assertTrue($this->action()->execute($orphan));
    }

    #[Test]
    public function a_machine_missing_from_the_provider_is_not_a_successful_suspension(): void
    {
        /*
         * Drift, not success. Saying otherwise would close the case on a
         * customer's missing server.
         */
        $this->provider()->destroyVm($this->node->provider_name, '300');

        $this->assertFalse($this->suspend());
    }

    private function action(): EnforceComputeSuspension
    {
        return new EnforceComputeSuspension($this->providers);
    }

    private function suspend(?SuspensionPolicy $policy = null): bool
    {
        return $this->action()->execute($this->service->fresh(), $policy);
    }

    private function lift(?SuspensionPolicy $policy = null): bool
    {
        return (new LiftComputeSuspension($this->providers))->execute($this->service->fresh(), $policy);
    }

    private function provider(): ComputeProvider
    {
        return $this->providers->for($this->cluster);
    }

    private function remote(): ?RemoteVmState
    {
        return $this->provider()->getVm($this->node->provider_name, '300');
    }

    /**
     * Puts a different lock on the machine, as another operation would.
     */
    private function relock(string $lock): void
    {
        $this->provider()->suspendVm($this->node->provider_name, '300', SuspensionPolicy::RecordOnly);

        // The fake keeps its machines in memory; reaching in is the only way to
        // model a lock the platform did not set.
        $reflection = new \ReflectionProperty($this->provider(), 'machines');
        $machines = $reflection->getValue($this->provider());
        $current = $machines[$this->node->provider_name]['300'];

        $machines[$this->node->provider_name]['300'] = new RemoteVmState(
            providerId: $current->providerId,
            nodeName: $current->nodeName,
            name: $current->name,
            powerState: $current->powerState,
            vcpu: $current->vcpu,
            memoryMib: $current->memoryMib,
            diskGib: $current->diskGib,
            uptimeSeconds: $current->uptimeSeconds,
            lock: $lock,
            startsOnBoot: $current->startsOnBoot,
            raw: $current->raw,
        );

        $reflection->setValue($this->provider(), $machines);
    }
}
