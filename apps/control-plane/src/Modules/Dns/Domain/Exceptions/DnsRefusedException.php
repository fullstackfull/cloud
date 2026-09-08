<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Something the platform will not put in a zone, and why.
 *
 * Every constructor here names a rule a person could argue with, which is the
 * test for whether a refusal belongs in this class rather than in a form
 * request: a TTL out of range is a shape, and validation owns shapes; "that
 * address is not yours" is a decision about the world, and belongs here where
 * it can be read.
 */
final class DnsRefusedException extends DomainException
{
    private string $errorCode = 'dns.refused';

    private int $status = 422;

    public static function zoneAlreadyClaimed(string $name): self
    {
        return (new self('This domain is already held on this platform.'))
            ->withContext(['zone' => $name])
            ->as('dns.zone.already_claimed')
            ->withStatus(409);
    }

    public static function zoneIsReserved(string $name): self
    {
        return (new self('This domain is used by the platform itself and cannot be held by an account.'))
            ->withContext(['zone' => $name])
            ->as('dns.zone.reserved')
            ->withStatus(403);
    }

    /**
     * Reverse zones follow the address block, not the domain.
     *
     * An account holding `example.com` has no authority whatsoever over the
     * `in-addr.arpa` delegation for the addresses it points at — that is
     * delegated by whoever assigned the block, and on this platform it is set
     * per address in the IPAM surface.
     */
    public static function zoneIsReverse(string $name): self
    {
        return (new self('Reverse zones are delegated with the address block. Reverse names are set per address.'))
            ->withContext(['zone' => $name])
            ->as('dns.zone.is_reverse');
    }

    public static function tooManyZones(int $ceiling): self
    {
        return (new self('This account already holds as many zones as its plan allows.'))
            ->withContext(['limit' => $ceiling])
            ->as('dns.zone.limit_reached')
            ->withStatus(409);
    }

    public static function tooManyRecords(int $ceiling): self
    {
        return (new self('This zone already holds as many records as the platform allows.'))
            ->withContext(['limit' => $ceiling])
            ->as('dns.record.limit_reached')
            ->withStatus(409);
    }

    public static function nameOutsideZone(string $name, string $zone): self
    {
        return (new self('That name is not inside this zone.'))
            ->withContext(['name' => $name, 'zone' => $zone])
            ->as('dns.record.outside_zone');
    }

    /**
     * The rule that stops a name being pointed at somebody else's server.
     *
     * Only addresses this platform allocates are checked. A customer may point
     * their own name anywhere on the internet — that is what DNS is for — but
     * inside this platform's space an address belongs to whoever holds it, and
     * a name resolving to a stranger's machine is where virtual-host hijacking
     * and certificate issuance for a domain you do not control both begin.
     */
    public static function addressIsNotYours(string $address): self
    {
        return (new self('That address belongs to this platform and is not assigned to this account.'))
            ->withContext(['address' => $address])
            ->as('dns.record.address_not_yours')
            ->withStatus(403);
    }

    public static function cnameAtApex(string $zone): self
    {
        return (new self('A CNAME cannot stand for the whole domain: the apex has to carry its own SOA and NS records.'))
            ->withContext(['zone' => $zone])
            ->as('dns.record.cname_at_apex');
    }

    public static function cnameConflicts(string $name): self
    {
        return (new self('That name already has records of another type, and a CNAME has to be the only record at its name.'))
            ->withContext(['name' => $name])
            ->as('dns.record.cname_conflict')
            ->withStatus(409);
    }

    public static function conflictsWithCname(string $name): self
    {
        return (new self('That name is a CNAME, and a CNAME has to be the only record at its name.'))
            ->withContext(['name' => $name])
            ->as('dns.record.covered_by_cname')
            ->withStatus(409);
    }

    public static function duplicate(string $name): self
    {
        return (new self('That name already has a record of this type with this value.'))
            ->withContext(['name' => $name])
            ->as('dns.record.duplicate')
            ->withStatus(409);
    }

    public static function notEditable(string $id): self
    {
        return (new self('This record is on its way out and cannot be changed.'))
            ->withContext(['record' => $id])
            ->as('dns.record.not_editable')
            ->withStatus(409);
    }

    public static function zoneNotEditable(string $name): self
    {
        return (new self('This zone is on its way out and cannot be changed.'))
            ->withContext(['zone' => $name])
            ->as('dns.zone.not_editable')
            ->withStatus(409);
    }

    public static function confirmationDoesNotMatch(): self
    {
        return (new self('Type the zone name to confirm that it and every record in it are to be removed.'))
            ->as('dns.zone.not_confirmed');
    }

    public static function providerCannotCreateZones(): self
    {
        return (new self('This deployment is not configured to create zones.'))
            ->as('dns.zone.provider_cannot_create')
            ->withStatus(503);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }

    private function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }
}
