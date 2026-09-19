<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\DTOs;

use Lynomia\Modules\Dns\Domain\Enums\ZoneChangeKind;
use Lynomia\Modules\Dns\Domain\Enums\ZoneImportMode;

/**
 * Everything an import would do to a zone, computed and shown before any
 * of it is done — and refused as a whole while any line is refused.
 *
 * The fingerprint covers the changes AND the zone's current records, so a
 * plan previewed against one state of the zone cannot be applied against
 * another: an apply carries the fingerprint it previewed, and a mismatch is
 * a 409 that says "preview again", never a silent recompute.
 */
final readonly class ZoneImportPlan
{
    /**
     * @param  list<ZoneImportEntry>  $entries
     */
    public function __construct(
        public string $zoneId,
        public string $zoneName,
        public ZoneImportMode $mode,
        public array $entries,
        public string $fingerprint,
        public int $kept,
    ) {}

    public function count(ZoneChangeKind $kind): int
    {
        return count(array_filter($this->entries, static fn (ZoneImportEntry $e): bool => $e->kind === $kind));
    }

    public function isApplicable(): bool
    {
        return $this->count(ZoneChangeKind::Refused) === 0;
    }

    /**
     * @return list<ZoneImportEntry>
     */
    public function of(ZoneChangeKind $kind): array
    {
        return array_values(array_filter($this->entries, static fn (ZoneImportEntry $e): bool => $e->kind === $kind));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'zone_id' => $this->zoneId,
            'zone' => $this->zoneName,
            'mode' => $this->mode->value,
            'applicable' => $this->isApplicable(),
            'fingerprint' => $this->fingerprint,
            'counts' => [
                'add' => $this->count(ZoneChangeKind::Add),
                'update' => $this->count(ZoneChangeKind::Update),
                'remove' => $this->count(ZoneChangeKind::Remove),
                'unchanged' => $this->count(ZoneChangeKind::Unchanged),
                'refused' => $this->count(ZoneChangeKind::Refused),
                'ignored' => $this->count(ZoneChangeKind::Ignored),
                'kept' => $this->kept,
            ],
            'entries' => array_map(static fn (ZoneImportEntry $e): array => $e->toArray(), $this->entries),
        ];
    }
}
