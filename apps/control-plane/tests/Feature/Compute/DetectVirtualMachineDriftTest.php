<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Application\Actions\DetectVirtualMachineDrift;
use Lynomia\Modules\Compute\Domain\Contracts\ComputeProvider;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Application\Actions\RecordDrift;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The detector had no execution path at all before this phase: the drift table
 * and the whole operator review workflow existed, and nothing in the codebase
 * ever wrote a row to it from a real comparison. These tests drive it through
 * the fake hypervisor and assert both halves of the contract — that every
 * disagreement is recorded, and that nothing is created or destroyed to
 * resolve one.
 */
final class DetectVirtualMachineDriftTest extends TestCase
{
    use RefreshDatabase;

    private ComputeCluster $cluster;

    private ComputeNode $node;

    /*
     * One factory for the whole test. The container deliberately does not
     * share these — a factory holds live provider connections and must not
     * outlive a request — so resolving it twice would give the assertions a
     * fake hypervisor with none of the machines the test just created on the
     * other one.
     */
    private ComputeProviderFactory $providers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->providers = app(ComputeProviderFactory::class);

        $this->cluster = ComputeCluster::factory()->create(['status' => 'active']);

        $this->node = ComputeNode::factory()->withCapacity(16, 32_768, 1_000)->create([
            'cluster_id' => $this->cluster->id,
            'status' => NodeStatus::Active,
        ]);
    }

    #[Test]
    public function a_machine_the_hypervisor_no_longer_lists_is_recorded_as_critical(): void
    {
        $machine = VirtualMachine::factory()->onNode($this->node, 501)->create();

        $recorded = $this->detect();

        $this->assertSame(1, $recorded);

        $drift = $this->onlyDrift();

        $this->assertSame(DriftKind::MissingAtProvider, $drift->kind);
        $this->assertSame(DriftSeverity::Critical, $drift->severity);
        $this->assertSame('501', $drift->provider_reference);
        $this->assertSame($machine->service_id, $drift->service_id);
        $this->assertSame($machine->hostname, $drift->expected['hostname'] ?? null);

        // The remedy is a person, not the reconciler: the row the platform has
        // is still there to be looked at.
        $this->assertNotNull($machine->fresh());
        $this->assertSame(DriftStatus::Open, $drift->status);
    }

    #[Test]
    public function a_machine_the_platform_never_sold_is_recorded_as_an_orphan(): void
    {
        $this->createAtProviderOnly(vmId: 777, hostname: 'somebody-elses-box');

        $this->assertSame(1, $this->detect());

        $drift = $this->onlyDrift();

        $this->assertSame(DriftKind::OrphanAtProvider, $drift->kind);
        $this->assertSame(DriftSeverity::Warning, $drift->severity);
        $this->assertSame('777', $drift->provider_reference);
        $this->assertNull($drift->service_id);
        $this->assertSame('somebody-elses-box', $drift->observed['name'] ?? null);

        // Emphatically not destroyed. An orphan is unbilled, not unwanted.
        $this->assertNotNull($this->provider()->getVm($this->node->provider_name, '777'));
    }

    #[Test]
    public function disagreeing_about_power_is_information_rather_than_an_emergency(): void
    {
        $this->createAtProviderOnly(vmId: 900, hostname: 'vps-asleep', running: false);

        VirtualMachine::factory()->onNode($this->node, 900)->create(['power_state' => PowerState::Running]);

        $this->assertSame(1, $this->detect());

        $drift = $this->onlyDrift();

        $this->assertSame(DriftKind::StateMismatch, $drift->kind);
        $this->assertSame(DriftSeverity::Info, $drift->severity);
        $this->assertSame('running', $drift->expected['power_state'] ?? null);
        $this->assertSame('stopped', $drift->observed['power_state'] ?? null);
    }

    #[Test]
    public function a_machine_that_matches_is_not_drift(): void
    {
        $this->createAtProviderOnly(vmId: 120, hostname: 'vps-fine', running: true);

        VirtualMachine::factory()->onNode($this->node, 120)->create(['power_state' => PowerState::Running]);

        $this->assertSame(0, $this->detect());
        $this->assertSame(0, ResourceDrift::query()->count());
    }

    #[Test]
    public function a_machine_still_being_built_is_not_reported_missing(): void
    {
        // No provider id yet: the create call has not come back. Calling this
        // drift would page an operator every time somebody bought a server.
        VirtualMachine::factory()->create([
            'cluster_id' => $this->cluster->id,
            'node_id' => $this->node->getKey(),
            'provider_id' => null,
        ]);

        $this->assertSame(0, $this->detect());
        $this->assertSame(0, ResourceDrift::query()->count());
    }

    #[Test]
    public function a_node_an_operator_took_out_of_service_is_not_compared(): void
    {
        /*
         * A node in maintenance is one somebody is working on. Its machines
         * may legitimately be migrated away or its API may be answering with a
         * partial picture, and treating either as drift would bury the real
         * findings under noise generated by the maintenance itself.
         */
        $this->node->update(['status' => NodeStatus::Maintenance]);

        VirtualMachine::factory()->onNode($this->node, 501)->create();

        $this->assertSame(0, $this->detect());
        $this->assertSame(0, ResourceDrift::query()->count());
    }

    #[Test]
    public function seeing_the_same_drift_twice_does_not_produce_two_alerts(): void
    {
        VirtualMachine::factory()->onNode($this->node, 501)->create();

        $this->detect();
        $this->detect();
        $this->detect();

        $this->assertSame(1, ResourceDrift::query()->count());
        $this->assertSame(3, $this->onlyDrift()->occurrences);
    }

    private function detect(): int
    {
        $detect = new DetectVirtualMachineDrift($this->providers, app(RecordDrift::class));

        return $detect->execute($this->cluster->fresh());
    }

    private function provider(): ComputeProvider
    {
        return $this->providers->for($this->cluster);
    }

    private function createAtProviderOnly(int $vmId, string $hostname, bool $running = true): void
    {
        $this->provider()->createVirtualMachine(new CreateVmRequest(
            nodeName: $this->node->provider_name,
            vmId: $vmId,
            hostname: $hostname,
            vcpu: 2,
            memoryMib: 4096,
            diskGib: 40,
            storageName: 'local-lvm',
            networkBridge: 'vmbr0',
            startAfterCreate: $running,
        ));
    }

    private function onlyDrift(): ResourceDrift
    {
        $drifts = ResourceDrift::query()->get();

        $this->assertCount(1, $drifts);

        return $drifts->first();
    }
}
