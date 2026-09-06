<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;

/**
 * Gives a node's capacity back when a machine goes away.
 *
 * The mirror of ReserveNodeCapacity, and it takes the same lock for the same
 * reason: a release racing a reservation without one is a lost update in the
 * other direction, and capacity that quietly disappears from the fleet is
 * harder to notice than capacity that is oversold.
 *
 * Every counter is clamped at zero, which is a deliberate asymmetry with the
 * reservation path. A double release — a destroy retried after a timeout, a
 * reconciliation removing a machine the destroy job also removed — must not be
 * able to drive a counter negative, because a node with negative committed
 * memory advertises capacity it does not have and the scheduler would believe
 * it. Clamping loses the accounting for one machine; not clamping oversells
 * the node to every machine that comes after.
 */
final readonly class ReleaseNodeCapacity
{
    public function execute(ComputeNode $node, VmResources $resources): ComputeNode
    {
        return DB::transaction(function () use ($node, $resources): ComputeNode {
            /** @var ComputeNode $locked */
            $locked = ComputeNode::query()
                ->lockForUpdate()
                ->findOrFail($node->getKey());

            $locked->allocated_cpu_cores = max(0, $locked->allocated_cpu_cores - $resources->vcpu);
            $locked->allocated_memory_mib = max(0, $locked->allocated_memory_mib - $resources->memoryMib);
            $locked->allocated_storage_gib = max(0, $locked->allocated_storage_gib - $resources->diskGib);
            $locked->vm_count = max(0, $locked->vm_count - 1);
            $locked->save();

            return $locked;
        });
    }
}
