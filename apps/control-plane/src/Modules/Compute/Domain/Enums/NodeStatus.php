<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Enums;

/**
 * Whether a hypervisor node may take new machines.
 *
 * Only Active may. The other three are not degrees of undesirability that a
 * scheduler could weigh against a good memory score — draining means an
 * operator is moving work off this node, maintenance means it is about to be
 * rebooted, offline means it is gone. Placing a machine on any of them
 * produces a VM that has to be moved or rebuilt within the hour, so the
 * scheduler excludes them before scoring rather than penalising them.
 */
enum NodeStatus: string
{
    case Active = 'active';
    case Draining = 'draining';
    case Maintenance = 'maintenance';
    case Offline = 'offline';

    public function acceptsPlacement(): bool
    {
        return $this === self::Active;
    }

    /** Whether machines already here are expected to still be running. */
    public function holdsWorkloads(): bool
    {
        return $this !== self::Offline;
    }
}
