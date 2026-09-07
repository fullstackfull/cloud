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
}
