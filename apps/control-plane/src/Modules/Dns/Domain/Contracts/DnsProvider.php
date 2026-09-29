<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Contracts;

use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsNotConfiguredException;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\Services\DnsRecordIdentity;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone;
use Lynomia\Modules\Ipam\Domain\Contracts\ReverseDnsProvider;

/**
 * Whatever publishes forward DNS for the platform.
 *
 * Separate from {@see ReverseDnsProvider}
 * and that separation is the point rather than tidiness. Forward and reverse
 * DNS are different capabilities over different zones with different owners: an
 * account can hold `lynomia.test` and have no authority whatsoever over the
 * `in-addr.arpa` delegation for the addresses that domain resolves to, because
 * reverse zones follow IP allocation and are delegated by whoever assigned the
 * block. One interface covering both would let a provider that can do the first
 * be asked for the second and answer with something.
 *
 * There is no `has()` or `exists()`. Idempotence is the implementation's job —
 * `publish()` is defined as "make the zone say this", and an implementation
 * that already holds the value is expected to do nothing rather than write it
 * again.
 *
 * **A name holds as many records as were written to it.** Several A records
 * at one name are round robin; two MX records are a primary and a backup
 * exchanger. So no method here identifies a record by `(type, name)`: which
 * record a {@see DnsRecord} *is* is answered by
 * {@see DnsRecordIdentity} — the
 * provider's identifier while the zone still knows it, and otherwise the
 * value — and every implementation asks it rather than deciding for itself.
 * An implementation that collapsed a name's records onto one (F-11) would
 * destroy the others on the next publish or delete.
 */
interface DnsProvider
{
    /**
     * The driver name, as configuration spells it.
     */
    public function name(): string;

    /**
     * Every zone this account holds.
     *
     * @return list<DnsZone>
     *
     * @throws DnsProviderException
     * @throws DnsNotConfiguredException
     */
    public function zones(): array;

    /**
     * The zone serving exactly this name, or null.
     *
     * Exact match, not "the zone that would serve a record under this name" —
     * that is {@see self::zoneFor()}, and conflating them is how a record for
     * `mail.example.com` ends up written into a zone called `mail.example.com`
     * that somebody created by accident.
     *
     * @throws DnsProviderException
     * @throws DnsNotConfiguredException
     */
    public function findZone(string $name): ?DnsZone;

    /**
     * The zone that should hold a record for this fully-qualified name.
     *
     * Walks the labels outward, so a record for `a.b.example.com` lands in
     * `b.example.com` if that is delegated separately and in `example.com`
     * otherwise. Returns null when the account holds neither.
     *
     * @throws DnsProviderException
     * @throws DnsNotConfiguredException
     */
    public function zoneFor(string $fqdn): ?DnsZone;

    /**
     * Whether this deployment's configuration permits creating zones.
     *
     * Asked rather than attempted, because the answer depends on account
     * configuration rather than on the request, and because "create a zone and
     * find out" leaves a zone behind.
     */
    public function canCreateZones(): bool;

    /**
     * @throws DnsProviderException
     * @throws DnsNotConfiguredException when the account cannot create zones
     */
    public function createZone(string $name): DnsZone;

    /**
     * Give up the zone itself.
     *
     * Far more serious than removing every record in it: a zone that no longer
     * exists answers NXDOMAIN for every name under it, including names this
     * platform never wrote. Nothing calls this except an account deliberately
     * giving the domain up.
     *
     * Removing a zone that is not there is not an error, for the same reason
     * {@see self::delete()} says so: the caller asked for it to be absent.
     *
     * @throws DnsProviderException
     * @throws DnsNotConfiguredException
     */
    public function deleteZone(DnsZone $zone): void;

    /**
     * Records in a zone, optionally narrowed. All of them: a provider that
     * pages its listing is read to the last page, because a record missing
     * from this answer is reported as missing from the zone.
     *
     * @return list<DnsRecord>
     *
     * @throws DnsProviderException
     * @throws DnsNotConfiguredException
     */
    public function records(DnsZone $zone, ?DnsRecordType $type = null, ?string $name = null): array;

    /**
     * Make the zone say this, whether or not it said anything before.
     *
     * Idempotent by the record's identity, not by its name and type. A record
     * carrying an identifier the zone still holds replaces the record under
     * that identifier — an edit — and leaves every other record at the name
     * alone. A record the zone already holds by value is left as it is, or
     * rewritten only if what a resolver would see differs (its TTL included).
     * Anything else is added beside what the name already holds: publishing a
     * second address for a name is round robin, not a replacement.
     *
     * Returns the record as the provider now holds it, carrying the
     * provider's identifier. That identifier may belong to a record the value
     * tier found rather than one this call created; whether another row of
     * the platform's already holds it is the caller's question, not the
     * provider's, because nothing here can see the platform's table.
     *
     * @throws DnsProviderException
     * @throws DnsNotConfiguredException
     */
    public function publish(DnsZone $zone, DnsRecord $record): DnsRecord;

    /**
     * Remove one record — the one {@see DnsRecordIdentity} finds, and never
     * the other records at its name. Removing one that is not there is not an
     * error: the caller asked for the value to be absent, and it is.
     *
     * The identifier is not fired blind. An implementation reads first, so an
     * identifier the zone no longer knows (the record was removed in the
     * provider's console) falls through to the value like any other, rather
     * than being sent and answered with an error that parks the row for a
     * person over a zone that already says what was asked.
     *
     * The read is narrowed to the record's type and name. So a record renamed
     * at the provider under an identifier the platform holds is *not* removed
     * by this call: it now sits at a name the platform never asked to remove,
     * and the reconciliation sweep reports it as a record nobody here wrote.
     * The controlled fake resolves the same way, over the same narrowing.
     *
     * @throws DnsProviderException
     * @throws DnsNotConfiguredException
     */
    public function delete(DnsZone $zone, DnsRecord $record): void;
}
