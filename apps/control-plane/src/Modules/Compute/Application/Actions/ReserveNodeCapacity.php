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
    ): ComputeNode {
        return DB::transaction(function () use ($node, $resources, $architecture, $customerId, $antiAffinityLimit): ComputeNode {
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

            $locked->allocated_cpu_cores += $resources->vcpu;
            $locked->allocated_memory_mib += $resources->memoryMib;
            $locked->allocated_storage_gib += $resources->diskGib;
            $locked->vm_count += 1;
            $locked->save();

            return $locked;
        });
    }
}
