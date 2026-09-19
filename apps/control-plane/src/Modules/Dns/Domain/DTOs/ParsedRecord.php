<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\DTOs;

use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;

/**
 * One record as a zone file stated it, before any rule is applied: the
 * owner name resolved against the origin and lowercased, the content as
 * text, the TTL as seconds (or the automatic marker), the priority for MX
 * and the three fields for CAA. The line number is kept so a refusal can
 * point at the line.
 */
final readonly class ParsedRecord
{
    /**
     * @param  array<string, int|string>  $data
     */
    public function __construct(
        public int $line,
        public DnsRecordType $type,
        public string $name,
        public string $content,
        public int $ttl,
        public ?int $priority,
        public array $data,
    ) {}

    /**
     * The identity of a record in a set: type, owner and value. Two records
     * that share it are the same record whatever their TTLs.
     */
    public function key(): string
    {
        return $this->type->value.'|'.$this->name.'|'.strtolower($this->content);
    }
}
