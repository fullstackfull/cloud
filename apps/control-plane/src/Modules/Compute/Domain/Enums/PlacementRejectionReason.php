<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Enums;

/**
 * Why a node was not chosen.
 *
 * Every rejection is recorded with one of these so that an operator asking
 * "why did this order fail to place?" gets an answer from the decision itself
 * rather than from re-running the scheduler by hand against a fleet that has
 * since changed.
 */
enum PlacementRejectionReason: string
{
    case NodeNotActive = 'node_not_active';
    case NodeUnhealthy = 'node_unhealthy';
    case ArchitectureMismatch = 'architecture_mismatch';
    case InsufficientMemory = 'insufficient_memory';
    case CpuOvercommitExceeded = 'cpu_overcommit_exceeded';
    case InsufficientStorage = 'insufficient_storage';
    case CapacityThresholdExceeded = 'capacity_threshold_exceeded';
    case NoStorageOfRequiredClass = 'no_storage_of_required_class';
    case AntiAffinity = 'anti_affinity';
    case NotInAffinityGroup = 'not_in_affinity_group';
    case Excluded = 'excluded';

    /**
     * Whether waiting would change the answer.
     *
     * A node rejected for capacity may well fit the same request tomorrow; one
     * rejected because it has no NVMe pool never will, and an order blocked on
     * that needs a human, not a retry.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::InsufficientMemory,
            self::CpuOvercommitExceeded,
            self::InsufficientStorage,
            self::CapacityThresholdExceeded,
            self::AntiAffinity,
            self::NodeNotActive,
            self::NodeUnhealthy => true,
            self::ArchitectureMismatch,
            self::NoStorageOfRequiredClass,
            self::NotInAffinityGroup,
            self::Excluded => false,
        };
    }
}
