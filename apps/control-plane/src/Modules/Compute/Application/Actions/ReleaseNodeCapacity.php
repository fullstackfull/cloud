<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;

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
    public function execute(
        ComputeNode $node,
        VmResources $resources,
        ?string $storageId = null,
        ?string $reservationKey = null,
    ): ComputeNode {
        return DB::transaction(function () use ($node, $resources, $storageId, $reservationKey): ComputeNode {
            /*
             * A release for a key that is already released returns without
             * touching a counter. Clamping alone would stop the numbers going
             * negative, but it would still lose one machine's worth of
             * accounting on every duplicate release — and duplicates are
             * normal here: a destroy retried after a timeout and a
             * reconciliation removing the same machine both arrive.
             */
            $reservation = null;

            if ($reservationKey !== null) {
                $reservation = NodeCapacityReservation::query()
                    ->where('reservation_key', $reservationKey)
                    ->lockForUpdate()
                    ->first();

                if ($reservation === null || ! $reservation->isLive()) {
                    return $node->fresh() ?? $node;
                }
            }

            /** @var ComputeNode $locked */
            $locked = ComputeNode::query()
                ->lockForUpdate()
                ->findOrFail($node->getKey());

            $locked->allocated_cpu_cores = max(0, $locked->allocated_cpu_cores - $resources->vcpu);
            $locked->allocated_memory_mib = max(0, $locked->allocated_memory_mib - $resources->memoryMib);
            $locked->allocated_storage_gib = max(0, $locked->allocated_storage_gib - $resources->diskGib);
            $locked->vm_count = max(0, $locked->vm_count - 1);
            $locked->save();

            /*
             * Stamped in the same transaction as the decrement, which is what
             * makes the guard at the top of this method mean anything: an
             * unstamped reservation is live for ever, so every duplicate
             * release passes the check and takes another machine's worth of
             * commitment off a node that is still running it. Clamping hides
             * that only while the node is empty.
             */
            $reservation?->forceFill(['released_at' => now()])->save();

            // The pool's committed figure is what placement actually trusts for
            // shared storage, so it has to be given back on the same clamped
            // terms as the node's counters.
            if ($storageId !== null) {
                /** @var ComputeStorage|null $storage */
                $storage = ComputeStorage::query()->lockForUpdate()->find($storageId);

                if ($storage !== null) {
                    $storage->committed_gib = max(0, (int) $storage->committed_gib - $resources->diskGib);
                    $storage->save();
                }
            }

            return $locked;
        });
    }
}
