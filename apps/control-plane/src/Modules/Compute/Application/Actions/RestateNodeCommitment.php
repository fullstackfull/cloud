<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Application\Services\CapacityLocks;
use Lynomia\Modules\Compute\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\Services\NodeCapacityPolicy;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;

/**
 * Makes a machine's commitment say what the machine now is: this shape, on
 * this node, in this pool.
 *
 * ReserveNodeCapacity commits a machine as it is placed, and
 * ReleaseNodeCapacity gives it back as it goes. Between the two a machine can
 * change under its commitment, and nothing used to follow it:
 *
 *  - a resize changed the machine's shape and left the node's allocated_*
 *    figures, the pool's committed_gib and the reservation as they were
 *    bought. A grown machine oversold its node - the re-audit put 145536 MiB
 *    on a 131072 MiB node through a plan upgrade - and a destroy gave back
 *    the shape it was bought at (D2);
 *  - an adoption recorded a machine on one node while the build's commitment
 *    sat on another, where a retry had moved it: the machine's node charged
 *    nothing, the other charged for a machine it does not run (D5).
 *
 * So the live reservation under the key is rewritten in place, and exactly the
 * difference moves: on one node, the node and pool gain what the shape grew
 * by and give back what it shrank by; across nodes, the old node and pool
 * give back everything the row held and the new ones take the new shape.
 * With no live reservation under the key, one is written and the whole shape
 * committed, as ReserveNodeCapacity would - a machine whose commitment was
 * given back (a build released on failure, then adopted) is still a machine
 * on the node.
 *
 * $refuseWhatDoesNotFit decides what an increase that does not fit does. A
 * resize asks first and grows after, so it refuses (NodeCapacityExceededException,
 * nothing changed): the machine has not grown, and must not be grown onto
 * room the node does not have. An adoption, and a resize settling on what the
 * hypervisor confirmed, record a machine that already exists: refusing would
 * not make it smaller, only the ledger wrong, so they record it.
 *
 * The same lock order as every capacity path (ReserveNodeCapacity's class
 * docblock): the reservation row, then both nodes ascending by id, then both
 * pools ascending by id (CapacityLocks), and every figure is computed from
 * the rows as locked.
 */
final readonly class RestateNodeCommitment
{
    public function __construct(
        private NodeCapacityPolicy $policy,
        private CapacityLocks $locks,
    ) {}

    /**
     * @throws NodeCapacityExceededException when $refuseWhatDoesNotFit and an increase does not fit
     */
    public function execute(
        string $reservationKey,
        ComputeNode $node,
        ?string $storageId,
        VmResources $shape,
        bool $refuseWhatDoesNotFit,
        ?string $serviceId = null,
        ?string $customerId = null,
    ): NodeCapacityReservation {
        return DB::transaction(function () use ($reservationKey, $node, $storageId, $shape, $refuseWhatDoesNotFit, $serviceId, $customerId): NodeCapacityReservation {
            /** @var NodeCapacityReservation|null $held */
            $held = NodeCapacityReservation::query()
                ->where('reservation_key', $reservationKey)
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();

            $to = (string) $node->getKey();
            $from = $held === null ? null : (string) $held->node_id;
            $fromStorage = $held?->storage_id;

            $locked = $this->locks->nodesThenPools([$from, $to], [$fromStorage, $storageId]);
            $target = $locked['nodes'][$to] ?? null;

            if ($target === null) {
                /** @var ComputeNode $target */
                $target = ComputeNode::query()->findOrFail($to);
            }

            $sameNode = $from === $to;
            $samePool = $held !== null && $fromStorage === $storageId;

            // What the target node and pool gain (negative: give back).
            $was = $held !== null && $sameNode ? $held->resources() : null;
            $gain = [
                'vcpu' => $shape->vcpu - ($was?->vcpu ?? 0),
                'memory' => $shape->memoryMib - ($was?->memoryMib ?? 0),
                'disk' => $shape->diskGib - ($was?->diskGib ?? 0),
            ];
            $poolGain = $shape->diskGib - ($held !== null && $samePool ? $held->disk_gib : 0);
            $targetPool = $storageId === null ? null : ($locked['storages'][$storageId] ?? null);

            if ($refuseWhatDoesNotFit) {
                $this->refuseWhatDoesNotFit($target, $gain, $targetPool, $poolGain);
            }

            // Given back where the row was, when it moves.
            if ($held !== null && ! $sameNode && isset($locked['nodes'][(string) $from])) {
                $this->add($locked['nodes'][(string) $from], -$held->vcpu, -$held->memory_mib, -$held->disk_gib, -1);
            }

            if ($held !== null && ! $samePool && $fromStorage !== null && isset($locked['storages'][$fromStorage])) {
                $this->addToPool($locked['storages'][$fromStorage], -$held->disk_gib);
            }

            $this->add($target, $gain['vcpu'], $gain['memory'], $gain['disk'], $sameNode ? 0 : 1);

            if ($targetPool !== null) {
                $this->addToPool($targetPool, $poolGain);
            }

            $values = [
                'node_id' => $to,
                'storage_id' => $targetPool === null ? null : $storageId,
                'vcpu' => $shape->vcpu,
                'memory_mib' => $shape->memoryMib,
                'disk_gib' => $shape->diskGib,
            ];

            if ($held !== null) {
                $held->forceFill($values)->save();

                return $held;
            }

            return NodeCapacityReservation::create([
                ...$values,
                'reservation_key' => $reservationKey,
                'service_id' => $serviceId,
                'customer_id' => $customerId,
            ]);
        });
    }

    /**
     * @param  array{vcpu: int, memory: int, disk: int}  $gain
     *
     * @throws NodeCapacityExceededException
     */
    private function refuseWhatDoesNotFit(ComputeNode $node, array $gain, ?ComputeStorage $pool, int $poolGain): void
    {
        $assessment = $this->policy->assessGrowth($node, $gain['vcpu'], $gain['memory'], $gain['disk']);

        if (! $assessment->fits) {
            throw NodeCapacityExceededException::forNode(
                (string) $node->getKey(),
                $node->provider_name,
                $assessment->reason ?? PlacementRejectionReason::Excluded,
                (string) $assessment->detail,
            );
        }

        if ($pool === null || $poolGain <= 0) {
            return;
        }

        $free = $pool->freeGib();

        if ($free !== null && $free < $poolGain) {
            throw NodeCapacityExceededException::forNode(
                $pool->node_id ?? (string) $pool->getKey(),
                $pool->provider_name,
                PlacementRejectionReason::InsufficientStorage,
                sprintf('pool has %d GiB uncommitted, needs %d GiB more', $free, $poolGain),
            );
        }
    }

    /**
     * Clamped at zero on the way down, as ReleaseNodeCapacity is and for the
     * same reason: a negative figure advertises capacity nobody has.
     */
    private function add(ComputeNode $node, int $vcpu, int $memoryMib, int $diskGib, int $machines): void
    {
        $node->allocated_cpu_cores = max(0, $node->allocated_cpu_cores + $vcpu);
        $node->allocated_memory_mib = max(0, $node->allocated_memory_mib + $memoryMib);
        $node->allocated_storage_gib = max(0, $node->allocated_storage_gib + $diskGib);
        $node->vm_count = max(0, $node->vm_count + $machines);
        $node->save();
    }

    private function addToPool(ComputeStorage $pool, int $diskGib): void
    {
        $pool->committed_gib = max(0, (int) $pool->committed_gib + $diskGib);
        $pool->save();
    }
}
