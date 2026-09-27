<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Services;

use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\Compute\Domain\ValueObjects\CapacityAssessment;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;

/**
 * The single answer to "can this node take this machine?".
 *
 * It exists as its own object because two callers ask the question at two
 * different moments — the scheduler while scoring, and ReserveNodeCapacity
 * again under a row lock — and if those two implementations ever disagreed,
 * the disagreement would show up as a node that passes scoring and is then
 * refused forever, or worse, as a node that scoring rejected being reserved
 * anyway. One implementation cannot drift from itself.
 *
 * Three ceilings apply, and they are genuinely different things:
 *
 *  - the memory headroom keeps the hypervisor's own memory out of the pool;
 *  - the capacity threshold keeps a slice of what remains unscheduled, so that
 *    a node failing elsewhere in the cluster has somewhere to evacuate to;
 *  - the CPU overcommit ratio bounds how far virtual cores may be sold beyond
 *    physical ones.
 *
 * Memory is never overcommitted. A node that swaps takes every machine on it
 * down together, and the customers who notice first are always the ones
 * running the most important workloads.
 */
final readonly class NodeCapacityPolicy
{
    private const int FALLBACK_THRESHOLD_PERCENT = 85;

    private int $capacityThresholdPercent;

    public function __construct(?int $capacityThresholdPercent = null)
    {
        $percent = $capacityThresholdPercent
            ?? (int) config('compute.scheduler.capacity_threshold_percent', self::FALLBACK_THRESHOLD_PERCENT);

        /*
         * Clamped rather than trusted. A threshold of 0 — which is what an
         * unset or non-numeric environment variable casts to — would make
         * every node in the fleet unschedulable and stop the platform selling
         * anything, and a value above 100 would silently disable the reserve
         * the threshold exists to keep.
         */
        $this->capacityThresholdPercent = max(1, min(100, $percent));
    }

    /**
     * Memory this node may commit before the evacuation reserve is eaten into.
     */
    public function schedulableMemoryMib(ComputeNode $node): int
    {
        return (int) floor($node->usableMemoryMib() * $this->capacityThresholdPercent / 100);
    }

    public function assess(
        ComputeNode $node,
        VmResources $resources,
        CpuArchitecture $architecture = CpuArchitecture::X86_64,
    ): CapacityAssessment {
        return $this->assessQuantities($node, $resources->vcpu, $resources->memoryMib, $resources->diskGib, $architecture);
    }

    /**
     * Whether a machine already on this node may grow by these amounts.
     *
     * The same ceilings as a placement, and only those: a machine that grows
     * in place is not being placed, so the node's status, health and
     * architecture are not asked again - a node put into maintenance takes no
     * new machines, but the ones it holds are still the size their customers
     * pay for. What is asked is whether the node can hold the growth, because
     * a growth it cannot hold is an oversold node (RestateNodeCommitment). An
     * amount of zero or less grows nothing and is counted as zero.
     */
    public function assessGrowth(ComputeNode $node, int $vcpu, int $memoryMib, int $diskGib): CapacityAssessment
    {
        return $this->assessQuantities($node, max(0, $vcpu), max(0, $memoryMib), max(0, $diskGib), null);
    }

    /**
     * @param  CpuArchitecture|null  $architecture  null for a growth in place, which asks only the ceilings
     */
    private function assessQuantities(
        ComputeNode $node,
        int $vcpu,
        int $memoryMib,
        int $diskGib,
        ?CpuArchitecture $architecture,
    ): CapacityAssessment {
        $usableMemory = $node->usableMemoryMib();
        $schedulableMemory = $this->schedulableMemoryMib($node);
        $usableCores = $node->usableCpuCores();
        $totalStorage = $node->storage_gib;

        $memoryAfter = $node->allocated_memory_mib + $memoryMib;
        $coresAfter = $node->allocated_cpu_cores + $vcpu;
        $storageAfter = $node->allocated_storage_gib + $diskGib;

        // Free capacity is measured against the threshold ceiling rather than
        // the physical one, so that scoring ranks nodes by the headroom the
        // platform is actually willing to use.
        $freeMemoryAfter = max(0, $schedulableMemory - $memoryAfter);
        $freeCoresAfter = max(0, $usableCores - $coresAfter);
        $freeStorageAfter = max(0, $totalStorage - $storageAfter);

        $reject = fn (PlacementRejectionReason $reason, string $detail): CapacityAssessment => CapacityAssessment::rejected(
            $reason,
            $detail,
            $freeMemoryAfter,
            $freeCoresAfter,
            $freeStorageAfter,
            $schedulableMemory,
            $usableCores,
            $totalStorage,
        );

        if ($architecture !== null && ! $node->status->acceptsPlacement()) {
            return $reject(
                PlacementRejectionReason::NodeNotActive,
                sprintf('the node is %s', $node->status->value),
            );
        }

        if ($architecture !== null && ! $node->is_healthy) {
            return $reject(
                PlacementRejectionReason::NodeUnhealthy,
                'the node last reported unhealthy',
            );
        }

        if ($architecture !== null && $node->architecture() !== $architecture) {
            return $reject(
                PlacementRejectionReason::ArchitectureMismatch,
                sprintf('the node is %s and the image is %s', $node->architecture()->value, $architecture->value),
            );
        }

        if ($memoryAfter > $usableMemory) {
            return $reject(
                PlacementRejectionReason::InsufficientMemory,
                sprintf(
                    'placing %d MiB would commit %d MiB of %d MiB usable',
                    $memoryMib,
                    $memoryAfter,
                    $usableMemory,
                ),
            );
        }

        /*
         * Checked after the hard memory limit and reported separately, because
         * the two mean different things to an operator: over the physical
         * ceiling is "buy more memory", over the threshold is "this node is as
         * full as policy allows, and the policy is a number you can change".
         */
        if ($memoryAfter > $schedulableMemory) {
            return $reject(
                PlacementRejectionReason::CapacityThresholdExceeded,
                sprintf(
                    'placing %d MiB would commit %d MiB, past the %d%% threshold of %d MiB',
                    $memoryMib,
                    $memoryAfter,
                    $this->capacityThresholdPercent,
                    $schedulableMemory,
                ),
            );
        }

        if ($coresAfter > $usableCores) {
            return $reject(
                PlacementRejectionReason::CpuOvercommitExceeded,
                sprintf(
                    'placing %d vCPU would commit %d of %d virtual cores at a %.2f× overcommit ratio',
                    $vcpu,
                    $coresAfter,
                    $usableCores,
                    $node->cpu_overcommit_ratio,
                ),
            );
        }

        if ($storageAfter > $totalStorage) {
            return $reject(
                PlacementRejectionReason::InsufficientStorage,
                sprintf(
                    'placing %d GiB would commit %d GiB of %d GiB',
                    $diskGib,
                    $storageAfter,
                    $totalStorage,
                ),
            );
        }

        return CapacityAssessment::fits(
            $freeMemoryAfter,
            $freeCoresAfter,
            $freeStorageAfter,
            $schedulableMemory,
            $usableCores,
            $totalStorage,
        );
    }
}
