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
 * row can answer, so a retired machine keeps its history instead of being
 * deleted or recycled into a new one: retirement keeps the row.
 *
 * ---------------------------------------------------------------------------
 * Who writes `retired`
 * ---------------------------------------------------------------------------
 *
 * An operator's act: `POST /api/admin/dedicated/{server}/retire` runs
 * `RetireDedicatedServer` behind the transition guard and the row lock, and
 * records the operator's evidence in the audit trail. It is a chassis leaving
 * the estate for good, not the tail of decommissioning —
 * `DecommissionDedicatedServer` writes `maintenance`, because a machine taken
 * back from a customer goes `active → maintenance → available` and its disks
 * are erased before it is sold again. Every other state lists `retired` among
 * its legal targets, seven doors in, and `retired` lists none out; that empty
 * list is the only thing standing between a machine sent for disposal and the
 * shelf, which `DecommissioningGivesTheAddressBackTest` pins through the
 * endpoints in both orders. That the state has a production writer at all is
 * held by `EveryStateAMachineCanEnterHasAProducerTest`.
 *
 * ---------------------------------------------------------------------------
 * What nobody writes: the date
 * ---------------------------------------------------------------------------
 *
 * `dedicated_servers.retired_at` has a column, a cast on the model and two
 * filters, and nothing in production writes `retired_at`: the retire act sets
 * the status alone, and the one writer the census described below finds is
 * the test factory's `retired()` state. So both `whereNull('retired_at')`
 * clauses — in `DedicatedServer::scopeAllocatable()` and in
 * `CustomerDedicatedServers::of()` — are permanently inert. Each is belt and
 * braces against a row carrying a retirement date under some other status; a
 * column with no production writer cannot produce that row, so neither clause
 * can ever be the one that excludes anything, and both read in review exactly
 * like a filter doing work. Neither query is wrong. In each, the status filter
 * alone keeps a retired machine out: `status = available` in one, a list of
 * held statuses without `retired` in the other.
 *
 * `RetirementKeepsTheRowAndStampsNoDateTest` holds this paragraph both ways.
 * The deletion direction is a string search of this docblock, and absolute.
 * The writer direction is a census of every PHP file under `src`, `app`,
 * `database`, `routes` and `resources`, with the factory as its known
 * positive, and so a classifier: it catches the ways a writer of the column
 * has been introduced here, and is not a proof that none can be. Its docblock
 * names the shapes it counts and the spellings it cannot see.
 *
 * When a production act starts stamping the date, the census goes red, and
 * that is the event it exists for: the two clauses start doing work, so this
 * paragraph is rewritten and the census with it. Adding the new writer beside
 * the factory would keep the census green by turning a claim about the
 * platform into a running total.
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

    /**
     * A customer's own machine is being rebuilt at their request.
     *
     * Distinct from `provisioning`, which means "being installed for an order
     * nobody has taken delivery of yet". The difference is who is attached: a
     * machine here already belongs to somebody, has their address on it and is
     * on their invoice, and none of that changes because its disks are being
     * replaced.
     */
    case Reinstalling = 'reinstalling';

    /** Out of service by an operator's decision: a repair, a firmware run, a wipe. */
    case Maintenance = 'maintenance';

    /**
     * The hardware is faulty. Not merely unreachable — distinguishing the two
     * takes an out-of-band check, which is one of the things the BMC is for.
     */
    case Failed = 'failed';

    /** Left the fleet for good, by an operator's act. Terminal: the row survives so the history does. */
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
     * Only during `provisioning` and `reinstalling`. PXE on an active machine
     * is a customer's entire server erased, and PXE on an available one is a
     * machine that reinstalls itself while nobody is watching.
     *
     * `reinstalling` is the second case where a network install is legitimate,
     * and a machine only reaches it by way of a customer typing its serial
     * back — which is to say, this is not a widening of when PXE is allowed,
     * it is the same rule with the second door named.
     */
    public function permitsNetworkInstall(): bool
    {
        return $this === self::Provisioning || $this === self::Reinstalling;
    }

    /** Whether a customer is attached to this machine right now. */
    public function isCustomerHeld(): bool
    {
        return match ($this) {
            self::Reserved, self::Provisioning, self::Active, self::Reinstalling => true,
            default => false,
        };
    }

    /** Whether the row may still change state at all. */
    public function isTerminal(): bool
    {
        return $this === self::Retired;
    }
}
