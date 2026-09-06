<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Enums;

/**
 * Where one physical machine is in its life.
 *
 * A VPS is created on demand; a dedicated server already exists in a rack and
 * is either free or it is not. That is why this enum is stock control rather
 * than a lifecycle: `available` is a countable unit of inventory, and every
 * other state is a reason a machine cannot be sold to the next customer.
 *
 * `retired` is terminal, and deliberately so. "What happened to my old server"
 * and "where did this serial number go" are questions only an unoverwritten
 * row can answer, so a decommissioned machine keeps its history instead of
 * being deleted or recycled into a new one.
 */
enum DedicatedServerStatus: string
{
    /** Racked, healthy, and sellable. The only state stock is counted from. */
    case Available = 'available';

    /** Held for one specific order. Not sellable, not yet being installed. */
    case Reserved = 'reserved';

    /** An unattended OS install is under way. The only state PXE is allowed in. */
    case Provisioning = 'provisioning';

    /** Delivered and running a customer's workload. */
    case Active = 'active';

    /** Out of service by an operator's decision: a repair, a firmware run, a wipe. */
    case Maintenance = 'maintenance';

    /**
     * The hardware is faulty. Not merely unreachable — distinguishing the two
     * takes an out-of-band check, which is one of the things the BMC is for.
     */
    case Failed = 'failed';

    /** Decommissioned. Terminal: the row survives so the history does. */
    case Retired = 'retired';

    /**
     * Whether a reservation may take this machine.
     *
     * Reservation filters on this and nothing else, which is what keeps a
     * retired serial or a machine mid-install out of the pool a customer's
     * order draws from.
     */
    public function isAllocatable(): bool
    {
        return $this === self::Available;
    }

    /**
     * Whether the platform may authorise a network install for a machine in
     * this state.
     *
     * Only during `provisioning`. PXE on an active machine is a customer's
     * entire server erased, and PXE on an available one is a machine that
     * reinstalls itself while nobody is watching.
     */
    public function permitsNetworkInstall(): bool
    {
        return $this === self::Provisioning;
    }

    /** Whether a customer is attached to this machine right now. */
    public function isCustomerHeld(): bool
    {
        return match ($this) {
            self::Reserved, self::Provisioning, self::Active => true,
            default => false,
        };
    }

    /** Whether the row may still change state at all. */
    public function isTerminal(): bool
    {
        return $this === self::Retired;
    }
}
