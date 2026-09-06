<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\Compute\Domain\Exceptions\NodeCapacityExceededException;
use Lynomia\Modules\Compute\Domain\Services\CustomerNodeCensus;
use Lynomia\Modules\Compute\Domain\Services\NodeCapacityPolicy;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;

/**
 * Commits a node's capacity to one machine.
 *
 * This is the only place compute_nodes.allocated_* and vm_count go up, and it
 * is the point at which a placement stops being an opinion and becomes a
 * promise.
 *
 * The re-check under the lock is the entire reason this action exists rather
 * than an increment at the call site. Scoring deliberately reads without a
 * lock — holding one across every node in the fleet would serialise every
 * order the platform takes — so two workers can both decide on the same node
 * in the same millisecond, and both would be right at the moment they decided.
 * The lock makes them take turns, and the second one re-reads the row it is
 * about to write and discovers the world has moved. Without that re-read, the
 * increment is a lost update: both commit, the node's committed memory exceeds
 * what it has, and the failure surfaces weeks later as the OOM killer choosing
 * somebody's database.
 */
final readonly class ReserveNodeCapacity
{
    public function __construct(
        private NodeCapacityPolicy $policy,
        private CustomerNodeCensus $census,
    ) {}

    /**
     * @param  string|null  $customerId  Whose machine this is. Given together with
     *                                   $antiAffinityLimit, the customer's existing machines on this
     *                                   node are counted again under the lock.
     * @param  int|null  $antiAffinityLimit  PlacementDecision::$antiAffinityLimit — the limit the
     *                                       scheduler enforced, or null when it deliberately waived it.
     *
     * @throws NodeCapacityExceededException
     */
    public function execute(
        ComputeNode $node,
        VmResources $resources,
        CpuArchitecture $architecture = CpuArchitecture::X86_64,
        ?string $customerId = null,
        ?int $antiAffinityLimit = null,
        ?string $storageId = null,
        ?string $reservationKey = null,
        ?string $serviceId = null,
    ): ComputeNode {
        return DB::transaction(function () use (
            $node, $resources, $architecture, $customerId, $antiAffinityLimit, $storageId, $reservationKey, $serviceId
        ): ComputeNode {
            /*
             * A retried provisioning job must not commit capacity twice.
             *
             * Without this the commitment is a bare counter increment, and the
             * second one never comes back: release is driven by destroying a
             * machine, and there is only ever one machine to destroy. The node
             * loses capacity permanently and silently, in proportion to how
             * often provisioning is retried — which is highest exactly when the
             * fleet is already under strain.
             */
            if ($reservationKey !== null) {
                $existing = NodeCapacityReservation::query()
                    ->where('reservation_key', $reservationKey)
                    ->whereNull('released_at')
                    ->first();

                if ($existing !== null) {
                    /** @var ComputeNode $alreadyCommitted */
                    $alreadyCommitted = ComputeNode::query()->findOrFail($existing->node_id);

                    return $alreadyCommitted;
                }
            }

            /*
             * Re-read under a row lock. The caller's copy was fetched during
             * scoring and is stale by definition: everything this action
             * guards against happened between then and now.
             */
            /** @var ComputeNode $locked */
            $locked = ComputeNode::query()
                ->lockForUpdate()
                ->findOrFail($node->getKey());

            $assessment = $this->policy->assess($locked, $resources, $architecture);

            if (! $assessment->fits) {
                throw NodeCapacityExceededException::forNode(
                    (string) $locked->getKey(),
                    $locked->provider_name,
                    $assessment->reason ?? PlacementRejectionReason::Excluded,
                    (string) $assessment->detail,
                );
            }

            /*
             * Anti-affinity, re-counted under the same lock and for the same
             * reason as capacity.
             *
             * The scheduler excludes a node the customer is already on, but it
             * does that before the lock, against machines that had already been
             * written. A customer clicking "order three" spawns three workers
             * that all score at once, all see an empty node, and all choose it
             * — and the customer ends up with three machines behind one power
             * supply, which is the specific outcome they paid extra to avoid.
             * Because the reservation shares its transaction with the write of
             * the virtual_machines row, whoever takes this lock second sees the
             * first machine and is turned away.
             *
             * Skipped when the scheduler waived the rule (a customer with more
             * machines than the cluster has nodes has to share), because
             * re-applying it here would refuse an order the scheduler
             * deliberately allowed.
             */
            if ($customerId !== null && $antiAffinityLimit !== null) {
                $existing = $this->census->countOnNode($customerId, (string) $locked->getKey());

                if ($existing >= $antiAffinityLimit) {
                    throw NodeCapacityExceededException::forNode(
                        (string) $locked->getKey(),
                        $locked->provider_name,
                        PlacementRejectionReason::AntiAffinity,
                        sprintf(
                            'this customer already has %d machine(s) here, and the limit is %d',
                            $existing,
                            $antiAffinityLimit,
                        ),
                    );
                }
            }

            /*
             * Storage is committed against the POOL, not the node.
             *
             * Shared storage is visible from every node in the cluster, so
             * counting it per node made the scheduler believe in as many copies
             * of the pool as there were nodes able to reach it, and the fleet
             * oversold it by exactly that factor. The symptom arrives as
             * customer machines failing to start on a full datastore, which
             * reads as a storage fault rather than a control-plane one.
             *
             * The node's own allocated_storage_gib is still maintained, because
             * it is what a per-node capacity report shows; it is no longer what
             * placement is allowed to trust for a shared pool.
             */
            if ($storageId !== null) {
                $this->commitStorage($storageId, $resources->diskGib);
            }

            $locked->allocated_cpu_cores += $resources->vcpu;
            $locked->allocated_memory_mib += $resources->memoryMib;
            $locked->allocated_storage_gib += $resources->diskGib;
            $locked->vm_count += 1;
            $locked->save();

            if ($reservationKey !== null) {
                /*
                 * Written inside the same transaction as the counters, so the
                 * attribution and the commitment can never disagree. The unique
                 * index on reservation_key is the real guard: two workers that
                 * both passed the check above serialise here, and the loser's
                 * transaction rolls back with its counter increment.
                 */
                NodeCapacityReservation::create([
                    'node_id' => $locked->getKey(),
                    'storage_id' => $storageId,
                    'reservation_key' => $reservationKey,
                    'service_id' => $serviceId,
                    'customer_id' => $customerId,
                    'vcpu' => $resources->vcpu,
                    'memory_mib' => $resources->memoryMib,
                    'disk_gib' => $resources->diskGib,
                ]);
            }

            return $locked;
        });
    }

    /**
     * Commits space out of a storage pool under its own row lock.
     *
     * Locked separately from the node because a shared pool is contended by
     * every node that can see it: two placements onto two different nodes are
     * not concurrent on the node row at all, and would both commit against a
     * pool that only had room for one.
     */
    private function commitStorage(string $storageId, int $diskGib): void
    {
        /** @var ComputeStorage $storage */
        $storage = ComputeStorage::query()->lockForUpdate()->findOrFail($storageId);

        $free = $storage->freeGib();

        if ($free !== null && $free < $diskGib) {
            throw NodeCapacityExceededException::forNode(
                $storage->node_id ?? $storageId,
                $storage->provider_name,
                PlacementRejectionReason::InsufficientStorage,
                sprintf('pool has %d GiB uncommitted, needs %d GiB', $free, $diskGib),
            );
        }

        $storage->committed_gib = (int) $storage->committed_gib + $diskGib;
        $storage->save();
    }
}
