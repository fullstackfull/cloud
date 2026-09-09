<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\DTOs;

use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Enums\ZoneChangeKind;

/**
 * One row of the preview: what would happen to one record, and why.
 */
final readonly class ZoneImportEntry
{
    /**
     * @param  array<string, int|string>  $data
     */
    public function __construct(
        public ZoneChangeKind $kind,
        public ?int $line,
        public ?DnsRecordType $type,
        public ?string $name,
        public ?string $content,
        public ?int $ttl,
        public ?int $priority,
        public array $data,
        public ?string $existingId,
        public ?string $reason,
    ) {}

    public static function fromRecord(ZoneChangeKind $kind, ParsedRecord $record, ?string $existingId = null): self
    {
        return new self($kind, $record->line, $record->type, $record->name, $record->content, $record->ttl, $record->priority, $record->data, $existingId, null);
    }

    public static function problem(ZoneChangeKind $kind, ParsedProblem $problem): self
    {
        return new self($kind, $problem->line, null, null, $problem->text, null, null, [], null, $problem->reason);
    }

    public static function refusedRecord(ParsedRecord $record, string $reason): self
    {
        return new self(ZoneChangeKind::Refused, $record->line, $record->type, $record->name, $record->content, $record->ttl, $record->priority, $record->data, null, $reason);
    }

    public static function refusedPlan(string $reason): self
    {
        return new self(ZoneChangeKind::Refused, null, null, null, null, null, null, [], null, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'line' => $this->line,
            'type' => $this->type?->value,
            'name' => $this->name,
            'content' => $this->content,
            'ttl' => $this->ttl,
            'priority' => $this->priority,
            'existing_id' => $this->existingId,
            'reason' => $this->reason,
        ];
    }
}
