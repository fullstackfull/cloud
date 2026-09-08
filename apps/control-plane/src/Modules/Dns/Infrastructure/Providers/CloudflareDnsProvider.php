<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Infrastructure\Providers;

use Lynomia\Modules\Dns\Domain\Contracts\DnsProvider;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsNotConfiguredException;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsProviderException;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsZone;
use Lynomia\Modules\Dns\Infrastructure\CloudflareApi;
use Lynomia\Modules\Dns\Infrastructure\CloudflareConnection;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * Forward DNS through Cloudflare's v4 API.
 *
 * Two things about this adapter are deliberate and worth reading before
 * changing it.
 *
 * **Nothing is retried here.** A create that times out may have created the
 * record; asking again is how a zone ends up with two answers for one name.
 * The timeout is reported as indeterminate and the decision is the platform's.
 *
 * **A publish reads before it writes.** That costs a round trip and buys
 * idempotence the interface promises: the same record published twice leaves
 * one, and a record already holding the wanted value is left alone rather than
 * rewritten, which would bump the zone serial and re-propagate for nothing.
 */
final class CloudflareDnsProvider implements DnsProvider
{
    public const string NAME = 'cloudflare';

    /**
     * Cloudflare's maximum. Asked for explicitly so that an account with more
     * zones than one page pages rather than silently seeing the first fifty.
     */
    private const int PAGE_SIZE = 50;

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
        $query = ['per_page' => 100];

        if ($type !== null) {
            $query['type'] = $type->value;
        }

        if ($name !== null) {
            $query['name'] = strtolower(trim($name, " \t\n\r\0\x0B."));
        }

        $body = $this->call('GET', '/zones/'.$zone->id().'/dns_records', $query, 'list records in '.$zone->name());

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($body['result'] ?? null) ? array_values($body['result']) : [];

        $records = [];

        foreach ($rows as $row) {
            $recordType = DnsRecordType::tryFrom((string) ($row['type'] ?? ''));

            // A zone holds types this platform does not publish — NS and SOA at
            // the very least. They are somebody else's records; skipping them
            // is not a gap, it is the boundary of what this contract covers.
            if ($recordType === null) {
                continue;
            }

            $records[] = DnsRecord::of(
                type: $recordType,
                name: (string) ($row['name'] ?? ''),
                content: (string) ($row['content'] ?? ''),
                ttl: (int) ($row['ttl'] ?? DnsRecord::AUTOMATIC_TTL),
                priority: isset($row['priority']) ? (int) $row['priority'] : null,
                data: is_array($row['data'] ?? null) ? $row['data'] : [],
                id: (string) ($row['id'] ?? ''),
            );
        }

        return $records;
    }

    public function publish(DnsZone $zone, DnsRecord $record): DnsRecord
    {
        $existing = $this->records($zone, $record->type(), $record->name());

        $current = $existing[0] ?? null;

        if ($current !== null && $current->saysTheSameAs($record)) {
            // Already what was asked for. Writing it again would bump the zone
            // serial and re-propagate a record that has not changed.
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

        return $id === '' ? $record : $record->withId($id);
    }

    public function delete(DnsZone $zone, DnsRecord $record): void
    {
        $existing = $record->id() !== null
            ? [$record]
            : $this->records($zone, $record->type(), $record->name());

        foreach ($existing as $found) {
            $id = $found->id();

            if ($id === null || $id === '') {
                continue;
            }

            $this->call('DELETE', '/zones/'.$zone->id().'/dns_records/'.$id, [], 'delete '.$record->type()->value.' '.$record->name());
        }
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
