<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Enums;

/**
 * The lifecycle of a single address.
 *
 *     available ─reserve→ reserved ─commit→ assigned
 *          ↑                   │                 │
 *          │                   │ release         │ releaseAssignment
 *          └───────────────────┘                 ↓
 *          └──── quarantine window expires ── quarantined
 *
 * A timeout's quarantine never takes the bottom edge: no window ends it,
 * because time does not tell anybody whether the timed-out build made a
 * machine. An operator who has looked ends it instead, by one of two edges
 * the drawing leaves out — quarantined → assigned when the machine exists
 * (IpAllocator::adoptQuarantinedAddress()), quarantined → available when it
 * does not (IpAllocator::releaseQuarantinedAddress()).
 *
 * `unavailable` is off to one side and has no incoming transition from the
 * allocator: it is stamped at seed time on the addresses that are not hosts
 * (network, broadcast, gateway) and on anything an operator has taken out of
 * service.
 */
enum IpAddressStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Assigned = 'assigned';
    case Quarantined = 'quarantined';
    case Unavailable = 'unavailable';

    /** Whether the allocator may hand this address to a customer. */
    public function isAllocatable(): bool
    {
        return $this === self::Available;
    }

    /** Whether the address is currently spoken for by a job or a service. */
    public function isHeld(): bool
    {
        return match ($this) {
            self::Reserved, self::Assigned => true,
            default => false,
        };
    }
}
