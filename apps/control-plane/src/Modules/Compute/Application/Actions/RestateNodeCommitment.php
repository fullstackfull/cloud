<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Actions;

use Closure;
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
 *
 * whyItWouldNotFit() asks the refusal alone, without a lock or a write: the
 * same reservation, the same gains (plan()) and the same checks
 * (refuseWhatDoesNotFit()), read as the rows stand. It is what the plan-change
 * quote asks before money moves (PlanChangeDelivery, through the machine's
 * commitment in the Vps module), so the quote and the resize refuse the same
 * growth. It is a courtesy read, as a quote is: the answer that holds is the
 * resize's own, under the locks.
 */
final readonly class RestateNodeCommitment
{
    public function __construct(
        private NodeCapacityPolicy $policy,
        private CapacityLocks $locks,
    ) {}

    /**
     * @param  VmResources|Closure(NodeCapacityReservation|null): VmResources  $shape  the shape to commit, or
     *                                                                                 how to work it out under the locks. A closure is called once the
     *                                                                                 reservation row (passed to it, as locked; null when none is live)
     *                                                                                 and the node and pool rows are held, so a shape read there from rows
     *                                                                                 another worker writes before it restates - a resize's machine row -
     *                                                                                 is read after that worker's restatement or before it, never between.
     *                                                                                 Two resizes of one machine settled from the machine each held in
     *                                                                                 memory left the commitment at the one that settled last, not at the
     *                                                                                 machine's shape (B1, round six).
     * @param  VmResources|null  $alreadyRuns  what the machine is known to run on $node now, when the caller
     *                                         has read it: only what the shape adds above it (and above what is
     *                                         held there) is refused, because the rest is already there
     *                                         (alreadyAbove()). Everything is still committed.
     *
     * @throws NodeCapacityExceededException when $refuseWhatDoesNotFit and an increase does not fit
     */
    public function execute(
        string $reservationKey,
        ComputeNode $node,
        ?string $storageId,
        VmResources|Closure $shape,
        bool $refuseWhatDoesNotFit,
        ?string $serviceId = null,
        ?string $customerId = null,
        ?VmResources $alreadyRuns = null,
    ): NodeCapacityReservation {
        return DB::transaction(function () use ($reservationKey, $node, $storageId, $shape, $refuseWhatDoesNotFit, $serviceId, $customerId, $alreadyRuns): NodeCapacityReservation {
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

            $shape = $shape instanceof Closure ? $shape($held) : $shape;
            $targetPool = $storageId === null ? null : ($locked['storages'][$storageId] ?? null);
            ['same_node' => $sameNode, 'same_pool' => $samePool, 'gain' => $gain, 'pool_gain' => $poolGain] = $this->plan($held, $to, $storageId, $shape);

            if ($refuseWhatDoesNotFit) {
                ['gain' => $asked, 'pool_gain' => $poolAsked] = self::alreadyAbove($gain, $poolGain, $shape, $alreadyRuns);
                $this->refuseWhatDoesNotFit($target, $asked, $targetPool, $poolAsked);
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
     * Why execute() with $refuseWhatDoesNotFit would refuse this, as the rows
     * stand now; null when it would not. Nothing is locked or written.
     *
     * @param  VmResources|Closure(NodeCapacityReservation|null): VmResources  $shape
     */
    public function whyItWouldNotFit(string $reservationKey, ComputeNode $node, ?string $storageId, VmResources|Closure $shape, ?VmResources $alreadyRuns = null): ?string
    {
        /** @var NodeCapacityReservation|null $held */
        $held = NodeCapacityReservation::query()
            ->where('reservation_key', $reservationKey)
            ->whereNull('released_at')
            ->first();

        /** @var ComputeNode|null $target */
        $target = ComputeNode::query()->find($node->getKey());

        if ($target === null) {
            return 'the machine\'s node is no longer recorded';
        }

        /** @var ComputeStorage|null $pool */
        $pool = $storageId === null ? null : ComputeStorage::query()->find($storageId);
        $shape = $shape instanceof Closure ? $shape($held) : $shape;
        $plan = $this->plan($held, (string) $target->getKey(), $storageId, $shape);

        try {
            ['gain' => $asked, 'pool_gain' => $poolAsked] = self::alreadyAbove($plan['gain'], $plan['pool_gain'], $shape, $alreadyRuns);
            $this->refuseWhatDoesNotFit($target, $asked, $pool, $poolAsked);
        } catch (NodeCapacityExceededException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * What the target node and pool gain from restating $held as $shape on
     * them (negative: give back), the one arithmetic execute() applies and
     * whyItWouldNotFit() asks about.
     *
     * @return array{same_node: bool, same_pool: bool, gain: array{vcpu: int, memory: int, disk: int}, pool_gain: int}
     */
    private function plan(?NodeCapacityReservation $held, string $to, ?string $storageId, VmResources $shape): array
    {
        $sameNode = $held !== null && (string) $held->node_id === $to;
        $samePool = $held !== null && $held->storage_id === $storageId;
        $was = $sameNode ? $held->resources() : null;

        return [
            'same_node' => $sameNode,
            'same_pool' => $samePool,
            'gain' => [
                'vcpu' => $shape->vcpu - ($was?->vcpu ?? 0),
                'memory' => $shape->memoryMib - ($was?->memoryMib ?? 0),
                'disk' => $shape->diskGib - ($was?->diskGib ?? 0),
            ],
            'pool_gain' => $shape->diskGib - ($samePool ? $held->disk_gib : 0),
        ];
    }

    /**
     * What is asked of the node and pool: the gain, less what the machine is
     * known to run there already - per dimension, the smaller of the gain
     * and what the shape adds above $alreadyRuns. A machine that runs a shape
     * its commitment does not hold (a growth that landed unrecorded, or no
     * commitment at all) occupies it on the node whatever the ledger says;
     * committing it records that, and refusing it would not make the machine
     * smaller. Null: the gain, all of it.
     *
     * @param  array{vcpu: int, memory: int, disk: int}  $gain
     * @return array{gain: array{vcpu: int, memory: int, disk: int}, pool_gain: int}
     */
    private static function alreadyAbove(array $gain, int $poolGain, VmResources $shape, ?VmResources $alreadyRuns): array
    {
        if ($alreadyRuns === null) {
            return ['gain' => $gain, 'pool_gain' => $poolGain];
        }

        return [
            'gain' => [
                'vcpu' => min($gain['vcpu'], $shape->vcpu - $alreadyRuns->vcpu),
                'memory' => min($gain['memory'], $shape->memoryMib - $alreadyRuns->memoryMib),
                'disk' => min($gain['disk'], $shape->diskGib - $alreadyRuns->diskGib),
            ],
            'pool_gain' => min($poolGain, $shape->diskGib - $alreadyRuns->diskGib),
        ];
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
