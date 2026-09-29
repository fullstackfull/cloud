<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Enums;

/**
 * The record types the platform publishes.
 *
 * Deliberately not every type a provider will accept. Each one here is a type
 * the platform has a reason to write — an address for a service, a name for a
 * panel, a verification token, mail routing, and the CAA record that stops any
 * other authority issuing a certificate for a customer's domain. A provider
 * that accepts SRV or DNSKEY is not a reason to expose them until something
 * here needs to write one.
 *
 * PTR is absent on purpose: reverse DNS is a different capability, on a
 * different zone, that a provider may not hold for the address in question at
 * all. It lives behind its own contract in the IPAM module.
 */
enum DnsRecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case CNAME = 'CNAME';
    case TXT = 'TXT';
    case MX = 'MX';
    case CAA = 'CAA';

    /**
     * Whether a record of this type is meaningless without a priority.
     *
     * Only MX. A provider will either reject a priority-less MX or invent one,
     * and both are worse than refusing to build the value object.
     */
    public function requiresPriority(): bool
    {
        return $this === self::MX;
    }

    /**
     * Whether this type carries structured fields rather than a single string.
     *
     * CAA is (flags, tag, value), and providers differ on whether they accept
     * the presentation form `0 issue "ca.example.net"` or insist on the three
     * fields separately. Modelling it as structured here means the adapter
     * decides how to send it, rather than every caller guessing.
     */
    public function isStructured(): bool
    {
        return $this === self::CAA;
    }

    /**
     * Whether two contents of this type that differ only in letter case are
     * the same value.
     *
     * A statement about record identity, not a display preference, so every
     * site that decides whether two records are one reads it from here: the
     * value object's comparison, the duplicate rule, and the zone-import
     * planner's key. It was once a planner-local `strtolower()` applied to
     * every type, which made a TXT value changed only in case read as
     * "unchanged" and never publish.
     *
     *  - A, AAAA: an address. A's validity gate admits no letters at all, so
     *    the answer is moot for it and given for completeness; AAAA's hex
     *    digits are case-insensitive by definition.
     *  - CNAME, MX: a host name, and DNS names are case-insensitive.
     *  - TXT: arbitrary text — a DKIM key or a verification token is
     *    compared byte for byte by whoever reads it.
     *  - CAA: the value is an issuer domain or an `iodef` URL, and the tag is
     *    compared case-insensitively by some readers and not others; treated
     *    as case-sensitive, so that a difference is shown rather than folded
     *    away.
     *
     * The unique index `dns_records_one_live_value` hashes content as stored
     * and is case-sensitive, so this can only ever add a refusal the index
     * would not make — never let through one it would.
     */
    public function contentIsCaseInsensitive(): bool
    {
        return match ($this) {
            self::A, self::AAAA, self::CNAME, self::MX => true,
            self::TXT, self::CAA => false,
        };
    }
}
