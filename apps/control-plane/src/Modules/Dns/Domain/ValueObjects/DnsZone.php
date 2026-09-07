<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\ValueObjects;

use Lynomia\Modules\Dns\Domain\Exceptions\InvalidDnsZoneException;

/**
 * A zone as the provider knows it: its own identifier, and the name it serves.
 *
 * The identifier is kept because every subsequent call needs it and looking
 * the zone up again per record is a round trip per record. The name is kept
 * because an operator reading a log needs to know which zone was written, and
 * an opaque provider id tells them nothing.
 */
final readonly class DnsZone
{
    private function __construct(
        private string $id,
        private string $name,
    ) {}

    /**
     * @throws InvalidDnsZoneException
     */
    public static function of(string $id, string $name): self
    {
        $id = trim($id);
        $name = strtolower(trim($name, " \t\n\r\0\x0B."));

        if ($id === '') {
            throw InvalidDnsZoneException::missingIdentifier($name);
        }

        if ($name === '') {
            throw InvalidDnsZoneException::missingName($id);
        }

        return new self($id, $name);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Whether a fully-qualified name belongs in this zone.
     *
     * Suffix matching on a label boundary, not `str_ends_with`: "notexample.com"
     * ends with "example.com" and is a different domain owned by somebody else.
     */
    public function covers(string $fqdn): bool
    {
        $fqdn = strtolower(trim($fqdn, " \t\n\r\0\x0B."));

        return $fqdn === $this->name || str_ends_with($fqdn, '.'.$this->name);
    }
}
