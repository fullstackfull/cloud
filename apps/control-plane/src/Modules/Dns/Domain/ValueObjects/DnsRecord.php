<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\ValueObjects;

use Lynomia\Modules\Dns\Domain\Enums\DnsRecordType;
use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsRecordException;
use Lynomia\Modules\Dns\Domain\Services\DnsRecordIdentity;

/**
 * One record, in the shape the platform means it — not in any provider's shape.
 *
 * Built through rules rather than assembled as an array, for the same reason
 * the reverse-DNS contract takes a Hostname: an adapter cannot be handed an MX
 * with no priority or a TTL a provider will silently clamp, because there is no
 * way to construct one.
 */
final readonly class DnsRecord
{
    /**
     * The provider's "let the zone decide" TTL. Cloudflare spells it 1; the
     * platform does not care what a provider calls it, only that a caller can
     * ask for the default without inventing a number.
     */
    public const int AUTOMATIC_TTL = 1;

    private const int MIN_TTL = 60;

    private const int MAX_TTL = 86400;

    /**
     * @param  array<string, mixed>  $data  Structured fields for types that have them
     */
    private function __construct(
        private DnsRecordType $type,
        private string $name,
        private string $content,
        private int $ttl,
        private ?int $priority,
        private array $data,
        private ?string $id,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidDnsRecordException
     */
    public static function of(
        DnsRecordType $type,
        string $name,
        string $content,
        int $ttl = self::AUTOMATIC_TTL,
        ?int $priority = null,
        array $data = [],
        ?string $id = null,
    ): self {
        $name = strtolower(trim($name, " \t\n\r\0\x0B."));

        if ($name === '') {
            throw InvalidDnsRecordException::missingName($type);
        }

        if (trim($content) === '' && $data === []) {
            throw InvalidDnsRecordException::missingContent($type, $name);
        }

        if ($type->requiresPriority() && $priority === null) {
            throw InvalidDnsRecordException::missingPriority($name);
        }

        if ($priority !== null && ($priority < 0 || $priority > 65535)) {
            throw InvalidDnsRecordException::priorityOutOfRange($name, $priority);
        }

        if ($ttl !== self::AUTOMATIC_TTL && ($ttl < self::MIN_TTL || $ttl > self::MAX_TTL)) {
            throw InvalidDnsRecordException::ttlOutOfRange($name, $ttl, self::MIN_TTL, self::MAX_TTL);
        }

        return new self($type, $name, trim($content), $ttl, $priority, $data, $id === null ? null : trim($id));
    }

    public function type(): DnsRecordType
    {
        return $this->type;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function ttl(): int
    {
        return $this->ttl;
    }

    public function priority(): ?int
    {
        return $this->priority;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    /**
     * The same record, now carrying the identifier the provider gave it.
     */
    public function withId(string $id): self
    {
        return new self($this->type, $this->name, $this->content, $this->ttl, $this->priority, $this->data, trim($id));
    }

    /**
     * Whether this is the same record as another: the value tier of
     * {@see DnsRecordIdentity}.
     *
     * Type, name, content, priority and structured data — every field that
     * says what the record *is*. Not TTL, which says how long resolvers keep
     * it: one value at two TTLs is one record whose TTL changed. Not the
     * provider's identifier, which is a handle on the record rather than a
     * fact about it.
     *
     * Content is compared case-insensitively exactly where
     * {@see DnsRecordType::contentIsCaseInsensitive()} says so, and data is
     * compared by its fields rather than by the order a provider happened to
     * list them in.
     *
     * This is **not** the question a publish asks before it skips a write;
     * that is {@see self::isPublishedExactlyAs()}. Asking this one there
     * meant a TTL-only edit was never sent.
     */
    public function saysTheSameAs(self $other): bool
    {
        return $this->type === $other->type
            && $this->name === $other->name
            && $this->sameContentAs($other)
            && $this->priority === $other->priority
            && self::canonicalData($this->data) === self::canonicalData($other->data);
    }

    /**
     * Whether a zone holding `$other` already serves exactly this.
     *
     * The record's identity plus its TTL. Used to make a publish idempotent
     * without a write: when the provider already holds this exact record, the
     * call is a no-op rather than an update that bumps a serial and
     * re-propagates a zone for nothing — and when anything a resolver would
     * see differs, including only the TTL, the write goes out.
     */
    public function isPublishedExactlyAs(self $other): bool
    {
        return $this->saysTheSameAs($other) && $this->ttl === $other->ttl;
    }

    private function sameContentAs(self $other): bool
    {
        if ($this->type->contentIsCaseInsensitive()) {
            return strtolower($this->content) === strtolower($other->content);
        }

        return $this->content === $other->content;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function canonicalData(array $data): array
    {
        ksort($data);

        return $data;
    }
}
