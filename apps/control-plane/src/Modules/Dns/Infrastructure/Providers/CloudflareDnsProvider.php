<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Infrastructure\Providers;

use Lynomia\Modules\Dns\Domain\Contracts\DnsProvider;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsNotConfiguredException;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\Services\DnsRecordIdentity;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\CloudflareApi;
use Lynomia\Modules\Dns\Infrastructure\CloudflareConnection;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Forward DNS through Cloudflare's v4 API.
 *
 * Three things about this adapter are deliberate and worth reading before
 * changing it.
 *
 * **Nothing is retried here.** A create that times out may have created the
 * record; asking again blind is how a zone ends up holding one value twice.
 * The timeout is reported as indeterminate and the decision is the platform's.
 *
 * **A publish reads before it writes, and so does a delete.** That costs a
 * round trip and buys the idempotence the interface promises: the same record
 * published twice leaves one, and a record already holding exactly the wanted
 * value is left alone rather than rewritten, which would bump the zone serial
 * and re-propagate for nothing.
 *
 * **A name holds several records, and this adapter touches one.** Which one is
 * {@see DnsRecordIdentity}'s answer — never "the first record at this name".
 */
final class CloudflareDnsProvider implements DnsProvider
{
    public const string NAME = 'cloudflare';

    /**
     * Cloudflare's maximum. Asked for explicitly so that an account with more
     * zones than one page pages rather than silently seeing the first fifty.
     */
    private const int PAGE_SIZE = 50;

    /**
     * Records are asked for a hundred at a time, and every page is read.
     */
    private const int RECORD_PAGE_SIZE = 100;

    private ?CloudflareConnection $connection = null;

    private ?CloudflareApi $api = null;

    public function __construct(
        private readonly SecretRedactor $redactor,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function zones(): array
    {
        $zones = [];
        $page = 1;

        do {
            $body = $this->call('GET', '/zones', ['per_page' => self::PAGE_SIZE, 'page' => $page], 'list zones');

            /** @var list<array<string, mixed>> $result */
            $result = is_array($body['result'] ?? null) ? array_values($body['result']) : [];

            foreach ($result as $row) {
                $zones[] = DnsZone::of(
                    (string) ($row['id'] ?? ''),
                    (string) ($row['name'] ?? ''),
                    $this->nameserversIn($row),
                );
            }

            $totalPages = (int) ($body['result_info']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $result !== []);

        return $zones;
    }

    public function findZone(string $name): ?DnsZone
    {
        $name = strtolower(trim($name, " \t\n\r\0\x0B."));

        if ($name === '') {
            return null;
        }

        $body = $this->call('GET', '/zones', ['name' => $name, 'per_page' => 1], 'look up zone '.$name);

        /** @var array<string, mixed>|null $row */
        $row = is_array($body['result'] ?? null) ? (array_values($body['result'])[0] ?? null) : null;

        if (! is_array($row)) {
            return null;
        }

        return DnsZone::of(
            (string) ($row['id'] ?? ''),
            (string) ($row['name'] ?? ''),
            $this->nameserversIn($row),
        );
    }

    public function zoneFor(string $fqdn): ?DnsZone
    {
        $labels = explode('.', strtolower(trim($fqdn, " \t\n\r\0\x0B.")));

        /*
         * Outward from the most specific: a record for a.b.example.com belongs
         * in b.example.com when that is delegated separately, and only then in
         * example.com. Walking inward instead would put every record in the
         * apex zone and quietly ignore a delegation.
         *
         * The single-label case is skipped: a bare TLD is not a zone anybody
         * here holds, and asking costs a round trip to be told so.
         */
        for ($i = 0; $i <= count($labels) - 2; $i++) {
            $candidate = implode('.', array_slice($labels, $i));

            $zone = $this->findZone($candidate);

            if ($zone !== null) {
                return $zone;
            }
        }

        return null;
    }

    public function canCreateZones(): bool
    {
        return $this->connection()->accountId() !== null;
    }

    public function createZone(string $name): DnsZone
    {
        $accountId = $this->connection()->accountId();

        if ($accountId === null) {
            throw DnsNotConfiguredException::cannotCreateZones(
                self::NAME,
                CloudflareConnection::CONFIGURATION_KEY.'.account_id',
            );
        }

        $body = $this->call('POST', '/zones', [
            'name' => strtolower(trim($name, " \t\n\r\0\x0B.")),
            'account' => ['id' => $accountId],
        ], 'create zone '.$name);

        /** @var array<string, mixed> $row */
        $row = is_array($body['result'] ?? null) ? $body['result'] : [];

        return DnsZone::of(
            (string) ($row['id'] ?? ''),
            (string) ($row['name'] ?? ''),
            $this->nameserversIn($row),
        );
    }

    /**
     * Remove the zone itself.
     *
     * Separate from removing its records and far more serious: a zone that is
     * gone answers NXDOMAIN for every name under it, including the ones this
     * platform never wrote. Nothing calls this except an account giving the
     * zone up deliberately.
     */
    public function deleteZone(DnsZone $zone): void
    {
        $this->call('DELETE', '/zones/'.$zone->id(), [], 'delete zone '.$zone->name());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function nameserversIn(array $row): array
    {
        $hosts = $row['name_servers'] ?? [];

        if (! is_array($hosts)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $host): string => (string) $host, $hosts));
    }

    public function records(DnsZone $zone, ?DnsRecordType $type = null, ?string $name = null): array
    {
        $query = ['per_page' => self::RECORD_PAGE_SIZE];

        if ($type !== null) {
            $query['type'] = $type->value;
        }

        if ($name !== null) {
            $query['name'] = strtolower(trim($name, " \t\n\r\0\x0B."));
        }

        $records = [];
        $page = 1;

        /*
         * Every page. This read once stopped at the first hundred records,
         * against a platform ceiling of 250 per zone
         * (`dns.records_per_zone`), so a large zone's records past the first
         * page were reported missing by the sweep and invisible to a publish
         * looking for the record it was about to create again.
         */
        do {
            $body = $this->call('GET', '/zones/'.$zone->id().'/dns_records', [...$query, 'page' => $page], 'list records in '.$zone->name());

            /** @var list<array<string, mixed>> $rows */
            $rows = is_array($body['result'] ?? null) ? array_values($body['result']) : [];

            foreach ($rows as $row) {
                $record = $this->recordFrom($row);

                if ($record !== null) {
                    $records[] = $record;
                }
            }

            $totalPages = (int) ($body['result_info']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $rows !== []);

        return $records;
    }

    /**
     * One listed row in the platform's shape, or null for a type the platform
     * does not publish.
     *
     * @param  array<string, mixed>  $row
     */
    private function recordFrom(array $row): ?DnsRecord
    {
        $recordType = DnsRecordType::tryFrom((string) ($row['type'] ?? ''));

        // A zone holds types this platform does not publish — NS and SOA at
        // the very least. They are somebody else's records; skipping them
        // is not a gap, it is the boundary of what this contract covers.
        if ($recordType === null) {
            return null;
        }

        $content = (string) ($row['content'] ?? '');

        /** @var array<string, mixed> $data */
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];

        /*
         * The two sides of this adapter have to agree on what a structured
         * record looks like. `payloadFor()` sends CAA as `data` and no
         * `content`, so the record it wrote can come back with no content at
         * all — while the platform's own record carries the presentation
         * form (`0 issue "letsencrypt.org"`) as content, built from the same
         * three fields. Left as read, a correctly published CAA compared as
         * a *different* record from the one the platform wrote: reported
         * missing and orphaned against one identifier on every sweep, and
         * duplicated by every republish. So the read rebuilds content from
         * the fields, in the platform's spelling, whatever the provider put
         * in `content` — the fields are what was sent, and what is compared.
         */
        if ($recordType->isStructured() && $data !== []) {
            $data = [
                'flags' => (int) ($data['flags'] ?? 0),
                'tag' => (string) ($data['tag'] ?? ''),
                'value' => (string) ($data['value'] ?? ''),
            ];
            $content = sprintf('%d %s "%s"', $data['flags'], $data['tag'], $data['value']);
        }

        return DnsRecord::of(
            type: $recordType,
            name: (string) ($row['name'] ?? ''),
            content: $content,
            ttl: (int) ($row['ttl'] ?? DnsRecord::AUTOMATIC_TTL),
            priority: isset($row['priority']) ? (int) $row['priority'] : null,
            data: $data,
            id: (string) ($row['id'] ?? ''),
        );
    }

    /**
     * Make the zone hold this record, touching no other record at its name.
     *
     * Read, then decide by {@see DnsRecordIdentity}: the record under the
     * identifier this one carries, or else the record already holding its
     * value, or else none. The read is narrowed to type and name only to keep
     * it small; nothing is concluded from *where* a record sits, only from
     * which record it is. Taking the first record at the name instead — as
     * this method once did — overwrote a round-robin address with its
     * sibling and replaced a primary mail exchanger with the backup.
     */
    public function publish(DnsZone $zone, DnsRecord $record): DnsRecord
    {
        $current = DnsRecordIdentity::findAmong($record, $this->records($zone, $record->type(), $record->name()));

        if ($current !== null && $current->isPublishedExactlyAs($record)) {
            // Already exactly what was asked for — TTL included, which is why
            // this is not `saysTheSameAs()`. Writing it again would bump the
            // zone serial and re-propagate a record that has not changed.
            return $current;
        }

        $payload = $this->payloadFor($record);

        if ($current === null) {
            $body = $this->call('POST', '/zones/'.$zone->id().'/dns_records', $payload, 'create '.$record->type()->value.' '.$record->name());
        } else {
            $body = $this->call('PUT', '/zones/'.$zone->id().'/dns_records/'.((string) $current->id()), $payload, 'update '.$record->type()->value.' '.$record->name());
        }

        /** @var array<string, mixed> $row */
        $row = is_array($body['result'] ?? null) ? $body['result'] : [];

        $id = (string) ($row['id'] ?? '');

        if ($id !== '') {
            return $record->withId($id);
        }

        // No identifier in the answer. A record rewritten under one the read
        // just returned keeps that one; a created record has none to carry.
        $held = $current?->id();

        return $held === null || $held === '' ? $record : $record->withId($held);
    }

    /**
     * Remove this one record and nothing else at its name.
     *
     * Reads first, even when the record carries an identifier. Firing a held
     * identifier blind meant a record already removed in the provider's
     * console answered with an error, and the row parked for a person over a
     * zone that already said what the customer asked — while the contract's
     * own words are that removing what is not there is not an error.
     */
    public function delete(DnsZone $zone, DnsRecord $record): void
    {
        $found = DnsRecordIdentity::findAmong($record, $this->records($zone, $record->type(), $record->name()));

        $id = $found?->id();

        if ($id === null || $id === '') {
            return;
        }

        $this->call('DELETE', '/zones/'.$zone->id().'/dns_records/'.$id, [], 'delete '.$record->type()->value.' '.$record->name());
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(DnsRecord $record): array
    {
        $payload = [
            'type' => $record->type()->value,
            'name' => $record->name(),
            'ttl' => $record->ttl(),
        ];

        if ($record->type()->isStructured() && $record->data() !== []) {
            // CAA is three fields, and Cloudflare wants them as fields. Sending
            // the presentation form as `content` is accepted by some providers
            // and silently mangled by others.
            $payload['data'] = $record->data();
        } else {
            $payload['content'] = $record->content();
        }

        if ($record->priority() !== null) {
            $payload['priority'] = $record->priority();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws DnsProviderException
     * @throws DnsNotConfiguredException
     */
    private function call(string $method, string $path, array $payload, string $operation): array
    {
        return $this->api()->call($method, $path, $payload, $operation);
    }

    /**
     * @throws DnsNotConfiguredException
     */
    private function api(): CloudflareApi
    {
        return $this->api ??= new CloudflareApi($this->connection(), $this->redactor, self::NAME);
    }

    /**
     * @throws DnsNotConfiguredException
     */
    private function connection(): CloudflareConnection
    {
        return $this->connection ??= CloudflareConnection::fromConfig(self::NAME);
    }
}
