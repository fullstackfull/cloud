<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;
use Lynomia\Modules\Dns\Domain\Services\DnsRecordRules;
use Lynomia\Modules\Dns\Domain\ValueObjects\DomainName;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;

/**
 * The rules that need to look at more than the record in front of them.
 *
 * {@see DnsRecordRules} answers "is this a well-formed MX"; this answers "may
 * this account put this MX in this zone", which needs the zone's other
 * records, the platform's address inventory, and a ceiling.
 *
 * Shared by adding and editing, because an edit that skipped these would be
 * the way round them: publish an A record, then edit it to point at a machine
 * belonging to somebody else.
 */
final readonly class AssertRecordFitsTheZone
{
    public function __construct(
        private DnsRecordRules $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  ?string  $ignoring  The record being edited, which must not count
     *                             as a conflict with itself.
     *
     * @throws DnsRefusedException
     * @throws InvalidDnsRecordException
     * @throws InvalidDomainNameException
     */
    public function execute(
        DnsZone $zone,
        DnsRecordType $type,
        string $name,
        string $content,
        ?int $priority,
        array $data,
        ?string $ignoring = null,
    ): void {
        if (! $zone->state->isEditable()) {
            throw DnsRefusedException::zoneNotEditable($zone->name);
        }

        $this->rules->assert($type, $name, $content, $priority, $data, $zone->name);

        $normalised = DomainName::fromString($name, allowWildcard: true)->value();

        $this->assertRoom($zone, $ignoring);
        $this->assertAddressIsTheirs($zone, $type, $content);
        $this->assertCnameStandsAlone($zone, $type, $normalised, $ignoring);
        $this->assertNotADuplicate($zone, $type, $normalised, $content, $ignoring);
    }

    /**
     * @throws DnsRefusedException
     */
    private function assertRoom(DnsZone $zone, ?string $ignoring): void
    {
        // An edit does not add a row, so it cannot be the one that goes over.
        if ($ignoring !== null) {
            return;
        }

        $ceiling = max(1, (int) config('dns.records_per_zone', 250));

        $held = DnsRecord::query()
            ->where('dns_zone_id', $zone->getKey())
            ->where('state', '!=', DnsState::Deleted->value)
            ->count();

        if ($held >= $ceiling) {
            throw DnsRefusedException::tooManyRecords($ceiling);
        }
    }

    /**
     * Refuse to point a name at a platform address the account does not hold.
     *
     * Only addresses this platform allocates are looked at. A customer may
     * point their own domain anywhere on the internet — that is what DNS is
     * for, and a platform that policed it would be a platform that decided
     * where its customers' names may point. Inside this platform's own space
     * the question is different: an address belongs to whoever holds it, and a
     * name resolving to a stranger's machine is where virtual-host hijacking
     * and certificate issuance for a domain somebody else controls both begin.
     *
     * @throws DnsRefusedException
     */
    private function assertAddressIsTheirs(DnsZone $zone, DnsRecordType $type, string $content): void
    {
        if ($type !== DnsRecordType::A && $type !== DnsRecordType::AAAA) {
            return;
        }

        /** @var IpAddress|null $address */
        $address = IpAddress::query()->where('address', $content)->first();

        // Not one of ours. Not our business.
        if ($address === null) {
            return;
        }

        $holds = IpAssignment::query()
            ->where('ip_address_id', $address->getKey())
            ->whereNull('released_at')
            ->where('customer_id', $zone->customer_id)
            ->exists();

        if (! $holds) {
            throw DnsRefusedException::addressIsNotYours($content);
        }
    }

    /**
     * A CNAME is an alias for a whole name, so nothing else may live there.
     *
     * Both directions, because both are wrong in the same way: a CNAME added
     * where records already exist, and a record added where a CNAME already
     * is. Resolvers handle the collision differently, which is worse than
     * either of them handling it badly.
     *
     * @throws DnsRefusedException
     */
    private function assertCnameStandsAlone(DnsZone $zone, DnsRecordType $type, string $name, ?string $ignoring): void
    {
        $atThisName = DnsRecord::query()
            ->where('dns_zone_id', $zone->getKey())
            ->where('name', $name)
            ->where('state', '!=', DnsState::Deleted->value)
            ->when($ignoring !== null, static fn ($query) => $query->whereKeyNot($ignoring));

        if ($type === DnsRecordType::CNAME) {
            if ($atThisName->clone()->exists()) {
                throw DnsRefusedException::cnameConflicts($name);
            }

            return;
        }

        if ($atThisName->clone()->where('type', DnsRecordType::CNAME->value)->exists()) {
            throw DnsRefusedException::conflictsWithCname($name);
        }
    }

    /**
     * @throws DnsRefusedException
     */
    private function assertNotADuplicate(
        DnsZone $zone,
        DnsRecordType $type,
        string $name,
        string $content,
        ?string $ignoring,
    ): void {
        $exists = DnsRecord::query()
            ->where('dns_zone_id', $zone->getKey())
            ->where('type', $type->value)
            ->where('name', $name)
            ->where('content', $content)
            ->where('state', '!=', DnsState::Deleted->value)
            ->when($ignoring !== null, static fn ($query) => $query->whereKeyNot($ignoring))
            ->exists();

        if ($exists) {
            throw DnsRefusedException::duplicate($name);
        }
    }
}
