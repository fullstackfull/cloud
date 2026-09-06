<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Application\Actions\ReleaseNodeCapacity;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Capacity commitment has to be idempotent for the same reason provisioning
 * jobs are: they are retried.
 *
 * As a bare counter increment, a retried job commits a second time for the same
 * machine and the second commitment never comes back — release is driven by
 * destroying a machine, and there is only ever one machine to destroy. The node
 * loses capacity permanently and silently, in proportion to how often
 * provisioning is retried, which is highest exactly when the fleet is already
 * under strain.
 */
final class IdempotentCapacityReservationTest extends TestCase
{
    use RefreshDatabase;

    private ComputeNode $node;

    protected function setUp(): void
    {
        parent::setUp();

        $this->node = ComputeNode::factory()->create([
            'cpu_cores' => 64,
            'memory_mib' => 262144,
            'storage_gib' => 4096,
        ]);
    }

    private function resources(): VmResources
    {
        return new VmResources(vcpu: 4, memoryMib: 8192, diskGib: 80);
    }

    #[Test]
    public function a_retried_reservation_with_the_same_key_commits_once(): void
    {
        $reserve = app(ReserveNodeCapacity::class);

        $reserve->execute($this->node, $this->resources(), reservationKey: 'job-abc');
        $reserve->execute($this->node, $this->resources(), reservationKey: 'job-abc');
        $reserve->execute($this->node, $this->resources(), reservationKey: 'job-abc');

        $node = $this->node->fresh();
        $this->assertSame(4, $node->allocated_cpu_cores);
        $this->assertSame(8192, (int) $node->allocated_memory_mib);
        $this->assertSame(1, $node->vm_count);
        $this->assertSame(1, NodeCapacityReservation::query()->count());
    }

    #[Test]
    public function different_keys_commit_separately(): void
    {
        $reserve = app(ReserveNodeCapacity::class);

        $reserve->execute($this->node, $this->resources(), reservationKey: 'job-one');
        $reserve->execute($this->node, $this->resources(), reservationKey: 'job-two');

        $this->assertSame(8, $this->node->fresh()->allocated_cpu_cores);
        $this->assertSame(2, $this->node->fresh()->vm_count);
    }

    #[Test]
    public function releasing_by_key_returns_the_capacity_exactly_once(): void
    {
        $reserve = app(ReserveNodeCapacity::class);
        $release = app(ReleaseNodeCapacity::class);

        $reserve->execute($this->node, $this->resources(), reservationKey: 'job-abc');

        // A destroy retried after a timeout, and a reconciliation removing the
        // same machine, both arrive. Clamping alone would stop the counters
        // going negative but would still lose one machine's worth of accounting.
        $release->execute($this->node, $this->resources(), reservationKey: 'job-abc');
        $release->execute($this->node, $this->resources(), reservationKey: 'job-abc');

        $node = $this->node->fresh();
        $this->assertSame(0, $node->allocated_cpu_cores);
        $this->assertSame(0, (int) $node->allocated_memory_mib);
        $this->assertSame(0, $node->vm_count);
    }

    #[Test]
    public function a_release_for_an_unknown_key_changes_nothing(): void
    {
        $reserve = app(ReserveNodeCapacity::class);
        $reserve->execute($this->node, $this->resources(), reservationKey: 'job-abc');

        app(ReleaseNodeCapacity::class)
            ->execute($this->node, $this->resources(), reservationKey: 'job-that-never-existed');

        // The live machine's capacity must survive a stray release.
        $this->assertSame(4, $this->node->fresh()->allocated_cpu_cores);
        $this->assertSame(1, $this->node->fresh()->vm_count);
    }

    #[Test]
    public function the_reservation_records_which_machine_holds_the_capacity(): void
    {
        app(ReserveNodeCapacity::class)->execute(
            $this->node,
            $this->resources(),
            reservationKey: 'job-abc',
            serviceId: '01JSERVICE0000000000000000',
        );

        $reservation = NodeCapacityReservation::query()->sole();

        // Without the attribution, a capacity discrepancy is unattributable:
        // an operator can see a node is committed but not to what.
        $this->assertSame($this->node->id, $reservation->node_id);
        $this->assertSame('01JSERVICE0000000000000000', $reservation->service_id);
        $this->assertSame(4, $reservation->resources()->vcpu);
        $this->assertTrue($reservation->isLive());
    }

    #[Test]
    public function reserving_without_a_key_still_works_for_callers_that_have_none(): void
    {
        // Inventory adjustments and manual placements have no job behind them.
        app(ReserveNodeCapacity::class)->execute($this->node, $this->resources());

        $this->assertSame(4, $this->node->fresh()->allocated_cpu_cores);
        $this->assertSame(0, NodeCapacityReservation::query()->count());
    }
}
