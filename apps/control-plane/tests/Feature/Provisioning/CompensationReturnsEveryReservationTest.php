<?php

declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A failed build gives back everything it took, not one of the things.
 *
 * The engine asks a single releaser to compensate, and that binding pointed at
 * IPAM alone. So an address came back and the node's committed cpu, memory and
 * storage did not — on every failed build, permanently. ReleaseNodeCapacity
 * had been written for exactly this and had no caller.
 *
 * The leak is invisible until the fleet is full: nothing errors, no test goes
 * red, and the node simply advertises less room than it has until somebody
 * edits the row by hand.
 */
final class CompensationReturnsEveryReservationTest extends TestCase
{
    use RefreshDatabase;

    private ComputeNode $node;

    private ProvisioningJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $cluster = ComputeCluster::factory()->create(['status' => 'active']);

        $this->node = ComputeNode::factory()->withCapacity(32, 65_536, 2_000)->create([
            'cluster_id' => $cluster->id,
        ]);

        $this->job = ProvisioningJob::factory()->create([
            'idempotency_key' => 'compensation-test-0001',
        ]);

        app(ReserveNodeCapacity::class)->execute(
            node: $this->node,
            resources: new VmResources(vcpu: 4, memoryMib: 8_192, diskGib: 100),
            customerId: $this->job->customer_id,
            reservationKey: 'compensation-test-0001',
            serviceId: $this->job->service_id,
        );

        $this->node->refresh();

        $this->assertSame(4, $this->node->allocated_cpu_cores);
        $this->assertSame(8_192, $this->node->allocated_memory_mib);
        $this->assertSame(1, $this->node->vm_count);
    }

    #[Test]
    public function releasing_hands_the_nodes_capacity_back(): void
    {
        app(ResourceReservationReleaser::class)->release($this->job);

        $this->node->refresh();

        $this->assertSame(0, $this->node->allocated_cpu_cores);
        $this->assertSame(0, $this->node->allocated_memory_mib);
        $this->assertSame(0, $this->node->allocated_storage_gib);
        $this->assertSame(0, $this->node->vm_count);

        $this->assertNotNull(
            NodeCapacityReservation::query()->where('reservation_key', 'compensation-test-0001')->sole()->released_at,
        );
    }

    #[Test]
    public function releasing_twice_does_not_free_a_running_machines_capacity(): void
    {
        /*
         * Duplicates are normal here: a compensation and a reconciliation can
         * both arrive for one machine. The second release must be a no-op
         * rather than a second decrement, or a node that is still running
         * other people's machines starts advertising their capacity as free.
         */
        app(ReserveNodeCapacity::class)->execute(
            node: $this->node->refresh(),
            resources: new VmResources(vcpu: 8, memoryMib: 16_384, diskGib: 200),
            customerId: null,
            reservationKey: 'somebody-elses-machine',
        );

        app(ResourceReservationReleaser::class)->release($this->job);
        app(ResourceReservationReleaser::class)->release($this->job);

        $this->node->refresh();

        // Exactly the other machine's commitment left standing.
        $this->assertSame(8, $this->node->allocated_cpu_cores);
        $this->assertSame(16_384, $this->node->allocated_memory_mib);
        $this->assertSame(1, $this->node->vm_count);
    }

    #[Test]
    public function quarantine_keeps_the_capacity_committed(): void
    {
        /*
         * The two adapters point opposite ways here, and both are the same
         * rule. An address that may be in use is held OUT of the pool; capacity
         * that may be in use is held AS committed. A machine the platform is
         * unsure about may be running on this node right now, using exactly
         * these resources, and handing the commitment back would let the
         * scheduler place another machine on top of it.
         */
        app(ResourceReservationReleaser::class)->quarantine($this->job, 'the provider never answered');

        $this->node->refresh();

        $this->assertSame(4, $this->node->allocated_cpu_cores);
        $this->assertSame(8_192, $this->node->allocated_memory_mib);
        $this->assertSame(1, $this->node->vm_count);

        // Still live, so the operator who resolves the job is the one who
        // decides — by adopting the machine, or by confirming it never existed.
        $this->assertNull(
            NodeCapacityReservation::query()->where('reservation_key', 'compensation-test-0001')->sole()->released_at,
        );
    }

    #[Test]
    public function a_job_that_reserved_nothing_compensates_cleanly(): void
    {
        // A build that failed before placement. There is nothing to give back
        // and compensation must not throw over it.
        $untouched = ProvisioningJob::factory()->create(['idempotency_key' => 'never-reserved-anything']);

        $this->assertSame(0, app(ResourceReservationReleaser::class)->release($untouched));

        $this->node->refresh();
        $this->assertSame(4, $this->node->allocated_cpu_cores);
    }
}
