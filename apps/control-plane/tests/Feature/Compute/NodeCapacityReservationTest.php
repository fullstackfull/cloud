<?php

declare(strict_types=1);

namespace Tests\Feature\Compute;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Compute\Application\Actions\ReleaseNodeCapacity;
use Lynomia\Modules\Compute\Application\Actions\ReserveNodeCapacity;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reservation is the moment a placement stops being an opinion.
 *
 * The behaviour worth pinning down is not that the counters move — it is that
 * they move against the row as it is *now*, under a lock, rather than against
 * whatever the caller was holding when it decided. Everything that can go
 * wrong between scoring and committing happens in that gap.
 */
final class NodeCapacityReservationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reserving_commits_the_machines_resources_to_the_node(): void
    {
        $node = ComputeNode::factory()->create();

        $reserved = app(ReserveNodeCapacity::class)->execute($node, new VmResources(4, 8192, 100));

        $this->assertSame(4, $reserved->allocated_cpu_cores);
        $this->assertSame(8192, $reserved->allocated_memory_mib);
        $this->assertSame(100, $reserved->allocated_storage_gib);
        $this->assertSame(1, $reserved->vm_count);

        $this->assertDatabaseHas('compute_nodes', [
            'id' => $node->getKey(),
            'allocated_memory_mib' => 8192,
            'vm_count' => 1,
        ]);
    }

    #[Test]
    public function reserving_rechecks_capacity_against_the_row_rather_than_the_callers_copy(): void
    {
        $node = ComputeNode::factory()->create([
            'memory_mib' => 16384,
            'memory_headroom_percent' => 0,
            'allocated_memory_mib' => 0,
        ]);

        /*
         * The caller's copy says the node is empty. It was true when scoring
         * read it, and it is exactly what a queue worker holds after a few
         * seconds in a job queue: another placement has landed since.
         */
        ComputeNode::query()->whereKey($node->getKey())->update(['allocated_memory_mib' => 16000]);

        $this->assertSame(0, $node->allocated_memory_mib);

        $this->expectException(NodeCapacityExceededException::class);

        app(ReserveNodeCapacity::class)->execute($node, new VmResources(2, 4096, 50));
    }

    #[Test]
    public function reserving_is_refused_once_the_node_has_been_taken_out_of_service(): void
    {
        $node = ComputeNode::factory()->create();

        // An operator drains the node in the seconds between the placement
        // decision and the job that acts on it.
        ComputeNode::query()->whereKey($node->getKey())->update(['status' => NodeStatus::Draining->value]);

        try {
            app(ReserveNodeCapacity::class)->execute($node, new VmResources(2, 4096, 50));
            $this->fail('Capacity was reserved on a node that is being drained.');
        } catch (NodeCapacityExceededException $e) {
            $this->assertSame(PlacementRejectionReason::NodeNotActive->value, $e->context()['reason']);
            $this->assertSame('compute.node_capacity_exceeded', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        $this->assertDatabaseHas('compute_nodes', ['id' => $node->getKey(), 'vm_count' => 0]);
    }

    #[Test]
    public function a_refused_reservation_leaves_the_counters_untouched(): void
    {
        $node = ComputeNode::factory()->create([
            'cpu_cores' => 4,
            'cpu_overcommit_ratio' => 1.0,
            'allocated_cpu_cores' => 4,
        ]);

        try {
            app(ReserveNodeCapacity::class)->execute($node, new VmResources(1, 4096, 50));
            $this->fail('A fifth core was sold on a four-core budget.');
        } catch (NodeCapacityExceededException) {
            // The transaction is what guarantees this: a partial reservation
            // would leave memory committed for a machine that was never built.
        }

        $this->assertDatabaseHas('compute_nodes', [
            'id' => $node->getKey(),
            'allocated_cpu_cores' => 4,
            'allocated_memory_mib' => 0,
            'vm_count' => 0,
        ]);
    }

    #[Test]
    public function releasing_gives_the_capacity_back(): void
    {
        $node = ComputeNode::factory()->allocated(8, 16384, 200, vmCount: 2)->create();

        $released = app(ReleaseNodeCapacity::class)->execute($node, new VmResources(4, 8192, 100));

        $this->assertSame(4, $released->allocated_cpu_cores);
        $this->assertSame(8192, $released->allocated_memory_mib);
        $this->assertSame(100, $released->allocated_storage_gib);
        $this->assertSame(1, $released->vm_count);
    }

    #[Test]
    public function a_second_release_of_the_same_machine_cannot_invent_capacity(): void
    {
        $node = ComputeNode::factory()->allocated(4, 8192, 100, vmCount: 1)->create();
        $resources = new VmResources(4, 8192, 100);

        // A destroy retried after a timeout, or reconciliation removing a
        // machine the destroy job had already removed.
        app(ReleaseNodeCapacity::class)->execute($node, $resources);
        $released = app(ReleaseNodeCapacity::class)->execute($node, $resources);

        // Clamped rather than negative: a node advertising more memory than it
        // has would be believed by the scheduler on every subsequent placement.
        $this->assertSame(0, $released->allocated_cpu_cores);
        $this->assertSame(0, $released->allocated_memory_mib);
        $this->assertSame(0, $released->allocated_storage_gib);
        $this->assertSame(0, $released->vm_count);
    }

    #[Test]
    public function reserving_and_releasing_a_machine_returns_the_node_to_where_it_started(): void
    {
        $node = ComputeNode::factory()->allocated(6, 12288, 150, vmCount: 3)->create();
        $resources = new VmResources(2, 4096, 50);

        $reserved = app(ReserveNodeCapacity::class)->execute($node, $resources);
        $this->assertSame(16384, $reserved->allocated_memory_mib);

        $released = app(ReleaseNodeCapacity::class)->execute($reserved, $resources);

        $this->assertSame(6, $released->allocated_cpu_cores);
        $this->assertSame(12288, $released->allocated_memory_mib);
        $this->assertSame(150, $released->allocated_storage_gib);
        $this->assertSame(3, $released->vm_count);
    }
}
