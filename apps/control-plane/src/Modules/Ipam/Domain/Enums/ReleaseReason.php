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

    /**
     * The provisioning job TIMED OUT rather than failed.
     *
     * These are not the same thing and must not be treated the same. A failure
     * means nothing was built and the address was never configured anywhere. A
     * timeout means the platform stopped waiting — the machine may exist right
     * now, answering on this address. Returning it to the pool would put two
     * machines on one address, and the resulting fault looks like a network
     * problem rather than a control-plane one.
     */
    case ProvisioningTimedOut = 'provisioning_timed_out';

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

    /**
     * Whether an address released for this reason must sit in quarantine
     * rather than returning to the pool immediately.
     *
     * A reservation that was never committed normally carries no history and
     * can be reused at once. A timeout is the exception: the platform does not
     * know whether the address was configured, and "probably not" is not good
     * enough when being wrong means two machines on one address.
     */
    public function requiresQuarantine(): bool
    {
        return match ($this) {
            self::ProvisioningTimedOut, self::Abuse => true,
            default => false,
        };
    }

    /**
     * Whether the quarantine this reason takes ends on a clock, or only when
     * somebody has been and looked.
     *
     * Every other quarantine is a waiting period: the address WAS in service,
     * its reputation decays, and after the pool's window it is safe to hand
     * on. A timeout is not a waiting period, because time answers none of its
     * question — the platform still does not know whether a machine was built
     * with this address configured on it, and it will not know a week later
     * either. Sweeping such a row back into the pool on a schedule puts the
     * next customer's NIC on an address a machine may still be answering on,
     * which is the exact outcome the timeout rule exists to prevent.
     *
     * Clearing it is an operator's act: they look at the provider, and then
     * either the resource is adopted (the address stays with the machine) or
     * it demonstrably does not exist (the address is released by hand, as
     * OperatorAction).
     */
    public function requiresOperatorClearance(): bool
    {
        return $this === self::ProvisioningTimedOut;
    }

    /**
     * The reasons whose quarantine an automatic sweep must never end.
     *
     * @return list<string>
     */
    public static function requiringOperatorClearance(): array
    {
        return array_values(array_map(
            static fn (self $reason): string => $reason->value,
            array_filter(self::cases(), static fn (self $reason): bool => $reason->requiresOperatorClearance()),
        ));
    }
}
