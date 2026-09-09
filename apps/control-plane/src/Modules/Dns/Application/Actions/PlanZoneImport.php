<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Application\Actions;

use Lynomia\Modules\Dns\Domain\DTOs\ParsedRecord;
use Lynomia\Modules\Dns\Domain\DTOs\ZoneImportEntry;
use Lynomia\Modules\Dns\Domain\DTOs\ZoneImportPlan;
use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Enums\ZoneChangeKind;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportMode;
use Lynomia\Modules\Dns\Domain\Exceptions\DnsRefusedException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDomainNameException;
use Lynomia\Modules\Dns\Domain\Services\DnsRecordRules;
use Lynomia\Modules\Dns\Domain\Services\ZoneFileParser;
use Lynomia\Modules\Dns\Domain\ValueObjects\DnsRecord as DnsRecordValue;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsRecord;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;

/**
 * Parse, normalise, validate, diff: the preview, computed the same way the
 * apply will compute it a moment later.
 *
 * Every rule the single-record path applies is applied here to every
 * incoming record, plus the ones a batch needs that a single record does
 * not: a CNAME may not stand beside another record in the FINAL set (the
 * zone as it would be after the import, not as it is), a value may not
 * appear twice in the file, and the zone's ceiling is measured on the
 * final count. A file with one refused line yields a plan that is not
 * applicable; the rows say which line and why, and nothing is skipped.
 *
 * `merge` leaves records the file does not mention alone and reports how
 * many. `replace` lists each of them as REMOVE so the customer sees, before
 * confirming, exactly which names stop resolving.
 */
final readonly class PlanZoneImport
{
    public function __construct(
        private ZoneFileParser $parser,
        private DnsRecordRules $rules,
        private AssertRecordFitsTheZone $fits,
    ) {}

    public function execute(DnsZone $zone, string $text, ZoneImportMode $mode): ZoneImportPlan
    {
        if (! $zone->state->isEditable()) {
            throw DnsRefusedException::zoneNotEditable($zone->name);
        }

        $parsed = $this->parser->parse($text, $zone->name);

        /** @var list<DnsRecord> $existing */
        $existing = DnsRecord::query()
            ->where('dns_zone_id', $zone->getKey())
            ->where('state', '!=', DnsState::Deleted->value)
            ->orderBy('name')->orderBy('type')->orderBy('content')
            ->get()
            ->all();

        $entries = [];
        foreach ($parsed->refused as $problem) {
            $entries[] = ZoneImportEntry::problem(ZoneChangeKind::Refused, $problem);
        }
        foreach ($parsed->ignored as $problem) {
            $entries[] = ZoneImportEntry::problem(ZoneChangeKind::Ignored, $problem);
        }

        $byKey = [];
        foreach ($existing as $row) {
            $byKey[$this->keyOf($row->type, $row->name, $row->content)] = $row;
        }

        $matched = [];
        $seen = [];
        /** @var list<array{type: DnsRecordType, name: string, source: string}> $final */
        $final = [];

        foreach ($parsed->records as $record) {
            $reason = $this->refusal($zone, $record);
            if ($reason !== null) {
                $entries[] = ZoneImportEntry::refusedRecord($record, $reason);

                continue;
            }

            $key = $record->key();
            if (isset($seen[$key])) {
                $entries[] = ZoneImportEntry::refusedRecord($record, sprintf('Line %d already states this record.', $seen[$key]));

                continue;
            }
            $seen[$key] = $record->line;

            $found = $byKey[$key] ?? null;

            if ($found instanceof DnsRecord) {
                $matched[(string) $found->getKey()] = true;
                $entries[] = $found->ttl === $record->ttl && $found->priority === $record->priority
                    ? ZoneImportEntry::fromRecord(ZoneChangeKind::Unchanged, $record, (string) $found->getKey())
                    : ZoneImportEntry::fromRecord(ZoneChangeKind::Update, $record, (string) $found->getKey());
                $final[] = ['type' => $record->type, 'name' => $record->name, 'source' => 'existing'];

                continue;
            }

            // A CNAME is single-valued: a different target at a name that
            // already has one is that record changing, not a second one.
            if ($record->type === DnsRecordType::CNAME) {
                $existingCname = $this->existingOfType($existing, DnsRecordType::CNAME, $record->name);
                if ($existingCname instanceof DnsRecord && ! isset($matched[(string) $existingCname->getKey()])) {
                    $matched[(string) $existingCname->getKey()] = true;
                    $entries[] = ZoneImportEntry::fromRecord(ZoneChangeKind::Update, $record, (string) $existingCname->getKey());
                    $final[] = ['type' => $record->type, 'name' => $record->name, 'source' => 'incoming'];

                    continue;
                }
            }

            $entries[] = ZoneImportEntry::fromRecord(ZoneChangeKind::Add, $record);
            $final[] = ['type' => $record->type, 'name' => $record->name, 'source' => 'incoming'];
        }

        $kept = 0;
        foreach ($existing as $row) {
            if (isset($matched[(string) $row->getKey()])) {
                continue;
            }

            if ($mode->removesWhatIsAbsent()) {
                $entries[] = new ZoneImportEntry(ZoneChangeKind::Remove, null, $row->type, $row->name, $row->content, $row->ttl, $row->priority, $row->data ?? [], (string) $row->getKey(), null);
            } else {
                $kept++;
                $final[] = ['type' => $row->type, 'name' => $row->name, 'source' => 'kept'];
            }
        }

        $entries = $this->refuseCnameCollisions($entries, $final);

        $ceiling = max(1, (int) config('dns.records_per_zone', 250));
        if (count($final) > $ceiling) {
            $entries[] = ZoneImportEntry::refusedPlan(sprintf('The zone would hold %d records; at most %d are served.', count($final), $ceiling));
        }

        return new ZoneImportPlan(
            (string) $zone->getKey(),
            $zone->name,
            $mode,
            $entries,
            $this->fingerprint($zone, $mode, $entries, $existing),
            $kept,
        );
    }

    /**
     * The single-record rules, as a reason or nothing.
     */
    private function refusal(DnsZone $zone, ParsedRecord $record): ?string
    {
        try {
            $this->rules->assert($record->type, $record->name, $record->content, $record->priority, $record->data, $zone->name);
            DnsRecordValue::of($record->type, $record->name, $record->content, $record->ttl, $record->priority, $record->data);
            $this->fits->assertAddressIsTheirs($zone, $record->type, $record->content);
        } catch (DnsRefusedException|InvalidDnsRecordException|InvalidDomainNameException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * A CNAME in the final set may not share its name with anything else.
     * Every incoming line involved is refused; a collision that exists only
     * between records already in the zone is not this import's doing.
     *
     * @param  list<ZoneImportEntry>  $entries
     * @param  list<array{type: DnsRecordType, name: string, source: string}>  $final
     * @return list<ZoneImportEntry>
     */
    private function refuseCnameCollisions(array $entries, array $final): array
    {
        $names = [];
        foreach ($final as $row) {
            $names[$row['name']][] = $row;
        }

        $collisions = [];
        foreach ($names as $name => $rows) {
            $hasCname = array_any($rows, static fn (array $r): bool => $r['type'] === DnsRecordType::CNAME);
            if ($hasCname && count($rows) > 1 && array_any($rows, static fn (array $r): bool => $r['source'] === 'incoming')) {
                $collisions[$name] = true;
            }
        }

        if ($collisions === []) {
            return $entries;
        }

        return array_map(static function (ZoneImportEntry $entry) use ($collisions): ZoneImportEntry {
            if ($entry->name !== null && isset($collisions[$entry->name]) && in_array($entry->kind, [ZoneChangeKind::Add, ZoneChangeKind::Update], true)) {
                return new ZoneImportEntry(ZoneChangeKind::Refused, $entry->line, $entry->type, $entry->name, $entry->content, $entry->ttl, $entry->priority, $entry->data, $entry->existingId, sprintf('%s would hold a CNAME beside another record; a CNAME must stand alone.', $entry->name));
            }

            return $entry;
        }, $entries);
    }

    /**
     * @param  list<DnsRecord>  $existing
     */
    private function existingOfType(array $existing, DnsRecordType $type, string $name): ?DnsRecord
    {
        foreach ($existing as $row) {
            if ($row->type === $type && $row->name === $name) {
                return $row;
            }
        }

        return null;
    }

    private function keyOf(DnsRecordType $type, string $name, string $content): string
    {
        return $type->value.'|'.$name.'|'.strtolower($content);
    }

    /**
     * @param  list<ZoneImportEntry>  $entries
     * @param  list<DnsRecord>  $existing
     */
    private function fingerprint(DnsZone $zone, ZoneImportMode $mode, array $entries, array $existing): string
    {
        $changes = array_values(array_filter($entries, static fn (ZoneImportEntry $e): bool => $e->kind->isChange()));
        usort($changes, static fn (ZoneImportEntry $a, ZoneImportEntry $b): int => strcmp(json_encode($a->toArray(), JSON_THROW_ON_ERROR), json_encode($b->toArray(), JSON_THROW_ON_ERROR)));

        $state = array_map(static fn (DnsRecord $r): string => implode('|', [(string) $r->getKey(), $r->type->value, $r->name, $r->content, (string) $r->ttl, (string) $r->priority, $r->state->value]), $existing);

        return hash('sha256', json_encode([
            'zone' => (string) $zone->getKey(),
            'mode' => $mode->value,
            'changes' => array_map(static fn (ZoneImportEntry $e): array => $e->toArray(), $changes),
            'state' => $state,
        ], JSON_THROW_ON_ERROR));
    }
}
