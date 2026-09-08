<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Infrastructure\Providers;

use Lynomia\Modules\Dns\Domain\Contracts\DnsProvider;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone;
use RuntimeException;

/**
 * A DNS provider that reaches no network and publishes nothing.
 *
 * Its behaviour is a pure function of the names it is given, so the paths that
 * matter can be reproduced without an HTTP stub: a record name carrying
 * {@see self::REFUSAL_MARKER} is refused as a real provider refuses a name it
 * will not host, and one carrying {@see self::TIMEOUT_MARKER} stops answering.
 * The second is the interesting one — it is the case the platform must not
 * resolve by trying again.
 *
 * It refuses to exist in production, on construction rather than on use. A fake
 * DNS provider does not merely fail to publish: it reports every record as
 * live, which is how a customer's mail keeps being delivered to an old address
 * while the platform's own dashboard says the name was moved.
 */
final class FakeDnsProvider implements DnsProvider
{
    public const string NAME = 'fake';

    /** A record whose name carries this is refused outright. */
    public const string REFUSAL_MARKER = 'dns-refused';

    /** A record whose name carries this times out: outcome unknown. */
    public const string TIMEOUT_MARKER = 'dns-timeout';

    /**
     * A credential-shaped string the refusal quotes back, because that is what
     * a real zone client does when it fails: it prints the request it sent,
     * headers and all. Nothing stores a provider message without redacting it,
     * and this is what makes that testable rather than assumed.
     */
    private const string ZONE_TOKEN = 'fake-cloudflare-token-0123456789';

    /** @var array<string, DnsZone> name => zone */
    private array $zones = [];

    /** @var array<string, array<string, DnsRecord>> zone id => key => record */
    private array $records = [];

    private int $nextId = 1;

    public function __construct()
    {
        // Checked on construction rather than by whoever builds it, so the
        // guard cannot be skipped by a caller that forgot to ask.
        if (app()->isProduction()) {
            throw new RuntimeException(
                'The fake DNS provider must never be constructed in production: '
                .'it reports records as published without publishing them.'
            );
        }
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Give the fake a zone to hold. Tests arrange the account this way rather
     * than by creating zones, because holding a zone and being allowed to
     * create one are different capabilities.
     */
    public function withZone(string $name): DnsZone
    {
        $id = 'zone-'.$this->nextId++;

        /*
         * Nameservers that look like a provider's, because the surface that
         * shows them to a customer has to be exercised by something. They are
         * derived from the zone id rather than fixed, so a test asserting on
         * them cannot pass by accident against a different zone.
         */
        $zone = DnsZone::of($id, $name, [$id.'-a.ns.fake.test', $id.'-b.ns.fake.test']);
        $this->zones[$zone->name()] = $zone;
        $this->records[$zone->id()] = [];

        return $zone;
    }

    public function zones(): array
    {
        return array_values($this->zones);
    }

    public function findZone(string $name): ?DnsZone
    {
        // A marked name misbehaves on *every* operation, not only the ones
        // that write. A provider that has stopped answering has stopped
        // answering questions too, and the platform's most dangerous moment is
        // when it asks "is this zone still there" and believes a silence.
        $this->refuseMarkedZone($name, 'find zone '.$name);

        return $this->zones[strtolower(trim($name, " \t\n\r\0\x0B."))] ?? null;
    }

    public function zoneFor(string $fqdn): ?DnsZone
    {
        $labels = explode('.', strtolower(trim($fqdn, " \t\n\r\0\x0B.")));

        for ($i = 0; $i <= count($labels) - 2; $i++) {
            $zone = $this->findZone(implode('.', array_slice($labels, $i)));

            if ($zone !== null) {
                return $zone;
            }
        }

        return null;
    }

    public function canCreateZones(): bool
    {
        return true;
    }

    public function createZone(string $name): DnsZone
    {
        $this->refuseMarkedZone($name, 'create zone '.$name);

        return $this->findZone($name) ?? $this->withZone($name);
    }

    /**
     * Give the zone up.
     *
     * Absent is absent: a zone the fake never held is not an error, because
     * the caller asked for it to be gone and it is.
     */
    public function deleteZone(DnsZone $zone): void
    {
        $this->refuseMarkedZone($zone->name(), 'delete zone '.$zone->name());

        unset($this->zones[$zone->name()], $this->records[$zone->id()]);
    }

    public function records(DnsZone $zone, ?DnsRecordType $type = null, ?string $name = null): array
    {
        $this->refuseMarkedZone($zone->name(), 'list records in '.$zone->name());

        $held = array_values($this->records[$zone->id()] ?? []);

        return array_values(array_filter($held, static function (DnsRecord $record) use ($type, $name): bool {
            if ($type !== null && $record->type() !== $type) {
                return false;
            }

            return $name === null || $record->name() === strtolower(trim($name, " \t\n\r\0\x0B."));
        }));
    }

    public function publish(DnsZone $zone, DnsRecord $record): DnsRecord
    {
        $this->refuseMarkedNames($record, 'publish '.$record->type()->value.' '.$record->name());

        $key = $record->type()->value.'|'.$record->name();

        // Keeps the identifier a repeat publish already has, so that the fake
        // is idempotent in the same observable way a real provider is: one
        // record, one id, whether it was written once or three times.
        $held = $this->records[$zone->id()][$key] ?? null;

        $stored = $record->withId($held?->id() ?? 'record-'.$this->nextId++);

        $this->records[$zone->id()][$key] = $stored;

        return $stored;
    }

    public function delete(DnsZone $zone, DnsRecord $record): void
    {
        $this->refuseMarkedNames($record, 'delete '.$record->type()->value.' '.$record->name());

        unset($this->records[$zone->id()][$record->type()->value.'|'.$record->name()]);
    }

    /**
     * @throws DnsProviderException
     */
    private function refuseMarkedNames(DnsRecord $record, string $operation): void
    {
        $this->refuseMarkedZone($record->name(), $operation);
    }

    /**
     * The same two markers, read from a zone name rather than a record's.
     *
     * Creating and giving up a zone are the operations with no record to carry
     * a marker, and they are exactly the ones where a timeout matters most: a
     * zone whose deletion did not answer may be gone, and asking again is how
     * a zone somebody recreated in the meantime gets deleted a second time.
     *
     * @throws DnsProviderException
     */
    private function refuseMarkedZone(string $name, string $operation): void
    {
        if (str_contains($name, self::TIMEOUT_MARKER)) {
            throw DnsProviderException::timedOut(self::NAME, $operation);
        }

        if (str_contains($name, self::REFUSAL_MARKER)) {
            throw DnsProviderException::refused(
                self::NAME,
                $operation,
                sprintf('POST /zones/dns_records 403 {"success":false} (Authorization: Bearer %s)', self::ZONE_TOKEN),
            );
        }
    }
}
