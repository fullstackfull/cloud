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
     * The slot a record takes in a zone on this platform: type, owner and
     * value — the columns of `dns_records_one_live_value`. Two records that
     * share it cannot both be held, whatever their TTLs, priorities or
     * fields; whether they are the *same* record is a further question (see
     * {@see self::statesTheSameAs()}).
     *
     * Content is folded only where {@see DnsRecordType::contentIsCaseInsensitive()}
     * says the type's content is case-insensitive. It used to be folded for
     * every type, so a TXT value changed only in case — a DKIM key rotated
     * with a different spelling — was planned `unchanged`, never published,
     * and the old one kept answering.
     */
    public function key(): string
    {
        return self::keyFor($this->type, $this->name, $this->content);
    }

    /**
     * The same key for a record that did not come from a file, so the
     * planner's two sides cannot drift apart.
     */
    public static function keyFor(DnsRecordType $type, string $name, string $content): string
    {
        return $type->value.'|'.$name.'|'.($type->contentIsCaseInsensitive() ? strtolower($content) : $content);
    }

    /**
     * Whether another line takes the same slot *and* says the same thing:
     * priority and fields as well as the key.
     */
    public function statesTheSameAs(self $other): bool
    {
        $mine = $this->data;
        $theirs = $other->data;
        ksort($mine);
        ksort($theirs);

        return $this->key() === $other->key() && $this->priority === $other->priority && $mine === $theirs;
    }
}
