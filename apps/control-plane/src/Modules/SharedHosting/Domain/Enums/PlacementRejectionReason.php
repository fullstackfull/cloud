<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * Why a node was not a candidate for an account.
 *
 * Recorded per node and tallied into the exhaustion exception, because "no
 * capacity" is the one scheduler outcome an operator has to act on, and the
 * fleet will have changed by the time anybody looks. The tally distinguishes
 * conditions that resolve on their own (a node coming out of maintenance) from
 * ones that need somebody to buy something (every node unlicensed, every node
 * full).
 */
enum PlacementRejectionReason: string
{
    case NodeNotAccepting = 'node_not_accepting';
    case NodeUnhealthy = 'node_unhealthy';
    case InMaintenance = 'in_maintenance';
    case Unlicensed = 'unlicensed';
    case DiskThresholdExceeded = 'disk_threshold_exceeded';
    case AccountLimitReached = 'account_limit_reached';
    case LoadThresholdExceeded = 'load_threshold_exceeded';
    case WrongRegion = 'wrong_region';
    case PackageIncompatible = 'package_incompatible';
    case PanelMismatch = 'panel_mismatch';
    case Excluded = 'excluded';

    /**
     * Whether waiting is likely to help.
     *
     * An unlicensed fleet does not fix itself, and telling a customer to wait
     * for one is worse than telling them the truth.
     */
    public function resolvesWithTime(): bool
    {
        return match ($this) {
            self::Unlicensed, self::WrongRegion, self::PackageIncompatible, self::PanelMismatch, self::Excluded => false,
            default => true,
        };
    }
}
