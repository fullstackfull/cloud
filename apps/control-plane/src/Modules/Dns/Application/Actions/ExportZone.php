<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord as DnsRecordValue;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;

/**
 * The zone as this platform holds it, as a BIND-compatible file another
 * provider can read.
 *
 * What is written: the records, with owner names relative to the origin,
 * explicit TTLs where the customer set one, targets absolute with the
 * trailing dot, TXT values quoted and escaped. A record the provider has
 * not confirmed yet carries a comment saying so, because a file that
 * presents a pending record as served is lying to the next provider.
 *
 * What is not: the provider's identifiers, anything about credentials, the
 * audit trail, the platform's own SOA and NS. The nameservers appear in a
 * comment for the person reading — they are where the zone is served NOW,
 * and a file meant to move a zone elsewhere must not carry them as records.
 */
final readonly class ExportZone
{
    /**
     * @return array{filename: string, content: string, record_count: int}
     */
    public function execute(DnsZone $zone): array
    {
        /** @var list<DnsRecord> $records */
        $records = DnsRecord::query()
            ->where('dns_zone_id', $zone->getKey())
            ->whereNotIn('state', [DnsState::Deleted->value, DnsState::Deleting->value])
            ->orderBy('name')->orderBy('type')->orderBy('content')
            ->get()
            ->all();

        $origin = $zone->name;
        $lines = [
            sprintf('; %s — exported from Lynomia on %s', $origin, CarbonImmutable::now()->toDateString()),
            '; Records only. The SOA and the apex NS are set by whichever nameservers serve the zone.',
        ];

        foreach ($zone->nameservers ?? [] as $nameserver) {
            $lines[] = sprintf('; served by %s', $nameserver);
        }

        $lines[] = sprintf('$ORIGIN %s.', $origin);
        $lines[] = '';

        foreach ($records as $record) {
            $lines[] = $this->line($record, $origin);
        }

        $lines[] = '';

        return [
            'filename' => $origin.'.zone',
            'content' => implode("\n", $lines),
            'record_count' => count($records),
        ];
    }

    private function line(DnsRecord $record, string $origin): string
    {
        $owner = $record->name === $origin
            ? '@'
            : (str_ends_with($record->name, '.'.$origin) ? substr($record->name, 0, -strlen('.'.$origin)) : $record->name.'.');

        $ttl = $record->ttl === DnsRecordValue::AUTOMATIC_TTL ? '' : (string) $record->ttl.' ';

        $rdata = match ($record->type) {
            DnsRecordType::A, DnsRecordType::AAAA => $record->content,
            DnsRecordType::CNAME => $record->content.'.',
            DnsRecordType::MX => sprintf('%d %s.', (int) $record->priority, $record->content),
            DnsRecordType::TXT => $this->quoted($record->content),
            DnsRecordType::CAA => sprintf('%d %s %s', (int) ($record->data['flags'] ?? 0), (string) ($record->data['tag'] ?? 'issue'), $this->quoted((string) ($record->data['value'] ?? ''))),
        };

        $note = $record->state === DnsState::Active ? '' : sprintf(' ; state: %s', $record->state->value);

        return sprintf('%s %sIN %s %s%s', $owner, $ttl, $record->type->value, $rdata, $note);
    }

    private function quoted(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
