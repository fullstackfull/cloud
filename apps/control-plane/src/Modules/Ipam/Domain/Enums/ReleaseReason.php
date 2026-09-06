<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Enums;

/**
 * Why an address stopped being held.
 *
 * The reason is not decoration. It is written to released_reason and, for
 * assignments, to quarantine_reason, and it decides how long the address then
 * sits out: an address released because a provisioning job failed was never
 * announced to the internet and carries no history, while one released after
 * an abuse suspension carries a reputation that outlives the customer by
 * weeks. Both are "released"; treating them the same is how a new customer
 * inherits somebody else's blocklist entry.
 */
enum ReleaseReason: string
{
    /** The provisioning job that held the reservation failed terminally. */
    case JobFailed = 'job_failed';

    /** The reservation was committed into an assignment — a happy ending. */
    case Committed = 'committed';

    /** The service that held the address was terminated normally. */
    case ServiceTerminated = 'service_terminated';

    /** The customer detached the address, or swapped it for another. */
    case CustomerRequest = 'customer_request';

    /** The address was reclaimed by an operator. */
    case OperatorAction = 'operator_action';

    /** The service was taken down for abuse; the address is tainted. */
    case Abuse = 'abuse';

    /** The address is being moved between pools or datacenters. */
    case Migration = 'migration';

    /**
     * Whether the address must sit out longer than the pool's normal window.
     *
     * An abuse-released address is the one case where the reputation problem
     * is known rather than merely possible.
     */
    public function isAbuse(): bool
    {
        return $this === self::Abuse;
    }

    /**
     * Whether releasing for this reason ends a healthy lifecycle. A committed
     * reservation is closed, not surrendered — nothing goes back to the pool.
     */
    public function isFulfilment(): bool
    {
        return $this === self::Committed;
    }
}
