<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Enums;

/**
 * Whether a hypervisor cluster may take new work.
 *
 * Degraded is deliberately distinct from offline: a cluster that has lost a
 * node still runs everything on it and must still answer status queries, but
 * nothing new should be placed there until an operator has looked.
 */
enum ClusterStatus: string
{
    case Active = 'active';
    case Degraded = 'degraded';
    case Maintenance = 'maintenance';
    case Offline = 'offline';

    /** Whether new machines may be placed in this cluster. */
    public function acceptsPlacement(): bool
    {
        return $this === self::Active;
    }

    /** Whether the platform should still talk to it at all. */
    public function isReachable(): bool
    {
        return $this !== self::Offline;
    }
}
