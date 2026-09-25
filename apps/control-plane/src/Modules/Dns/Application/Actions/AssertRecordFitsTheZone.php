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
        $this->assertNotADuplicate($zone, $type, $normalised, $content, $priority, $data, $ignoring);
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
    /**
     * Public because a zone import applies the same rule to every incoming
     * address before any of them is written, and a second copy of this
     * check would be a second place for it to be missing.
     */
    public function assertAddressIsTheirs(DnsZone $zone, DnsRecordType $type, string $content): void
    {
        if ($type !== DnsRecordType::A && $type !== DnsRecordType::AAAA) {
            return;
        }

        /** @var IpAddress|null $address */
        $address = IpAddress::query()->where('address', self::canonical($content))->first();

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
     * The one spelling of an address the inventory is looked up by.
     *
     * AAAA content is case-insensitive
     * ({@see DnsRecordType::contentIsCaseInsensitive()}) and has more than one
     * spelling besides — `2001:DB8::1`, `2001:0db8:0000:…:0001` — and a
     * byte-exact lookup matched only the one the inventory stored, so every
     * other spelling of a neighbour's address took the "not one of ours"
     * return and was accepted.
     *
     * `inet_pton()` reads a string as IPv6 exactly when it contains a colon.
     * On every other input this method is the identity: the dotted-quad set
     * `inet_pton()` accepts is exactly the set `inet_ntop()` writes back, and
     * anything it refuses is returned verbatim. So it changes nothing for an
     * IPv4 address, canonical or not — a non-canonical v4 spelling stored by
     * some future writer is not helped by it; only canonicalising the column
     * would close that. It matters on the day `ip_addresses` holds a v6 row,
     * which no writer in `src/` produces today.
     */
    private static function canonical(string $content): string
    {
        $content = trim($content);

        if (! str_contains($content, ':')) {
            return $content;
        }

        $packed = @inet_pton($content);

        if ($packed === false) {
            return $content;
        }

        $text = inet_ntop($packed);

        return $text === false ? $content : $text;
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
     * One value per name and type — and a refusal that says which rule it is.
     *
     * The table holds a value once per `(type, name)`
     * (`dns_records_one_live_value` hashes content alone), so the same MX host
     * at a second priority cannot be added. That is a limit of this platform,
     * not a duplicate: the two records do not say the same thing, and the
     * platform's own comparison calls them different. Refusing it as "a
     * record of this type with this value" told a customer two different
     * records were identical, which is the priority-blindness F-11 is about;
     * so the two cases carry two codes.
     *
     * Content is compared the way {@see DnsRecordType::contentIsCaseInsensitive()}
     * says: `Mail.Example.test` is the MX `mail.example.test` already holds.
     * The index is case-sensitive, so this only ever refuses more than it
     * would — never less.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws DnsRefusedException
     */
    private function assertNotADuplicate(
        DnsZone $zone,
        DnsRecordType $type,
        string $name,
        string $content,
        ?int $priority,
        array $data,
        ?string $ignoring,
    ): void {
        $wanted = trim($content);

        /** @var list<DnsRecord> $atThisName */
        $atThisName = DnsRecord::query()
            ->where('dns_zone_id', $zone->getKey())
            ->where('type', $type->value)
            ->where('name', $name)
            ->where('state', '!=', DnsState::Deleted->value)
            ->when($ignoring !== null, static fn ($query) => $query->whereKeyNot($ignoring))
            ->get()
            ->all();

        foreach ($atThisName as $row) {
            $sameContent = $type->contentIsCaseInsensitive()
                ? strtolower($row->content) === strtolower($wanted)
                : $row->content === $wanted;

            if (! $sameContent) {
                continue;
            }

            $held = $row->data ?? [];
            ksort($held);
            ksort($data);

            if ($row->priority === $priority && $held === $data) {
                throw DnsRefusedException::duplicate($name);
            }

            throw DnsRefusedException::oneValuePerName($name);
        }
    }
}
