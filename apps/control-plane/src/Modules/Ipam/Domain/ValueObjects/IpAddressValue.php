<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\ValueObjects;

use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidIpAddressException;
use Stringable;

/**
 * A syntactically valid IP address.
 *
 * Addresses live in the database as strings, because that is what every
 * hypervisor API, every DNS record and every operator's eyes expect. A string
 * column will however accept "10.0.0.256", " 10.0.0.1" and "10.0.0.01", and
 * those rows are only discovered later, by a hypervisor refusing to boot a VM.
 * Everything that writes an address goes through this type, so validation
 * happens once, on the way in.
 *
 * Note that the canonical form is the one PHP's own inet functions produce:
 * "010.0.0.1" and "10.0.0.1" must not become two rows for one address.
 *
 * @immutable
 */
final readonly class IpAddressValue implements Stringable
{
    private function __construct(
        private string $address,
        private IpVersion $version,
    ) {}

    public static function fromString(string $address): self
    {
        $trimmed = trim($address);

        // filter_var is the whole validation: it rejects out-of-range octets,
        // leading zeroes (which some resolvers read as octal) and anything
        // that merely looks numeric.
        if (filter_var($trimmed, FILTER_VALIDATE_IP) === false) {
            throw InvalidIpAddressException::forAddress($address);
        }

        $version = filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? IpVersion::V4
            : IpVersion::V6;

        return new self(self::canonicalise($trimmed, $version), $version);
    }

    public static function isValid(string $address): bool
    {
        return filter_var(trim($address), FILTER_VALIDATE_IP) !== false;
    }

    public function version(): IpVersion
    {
        return $this->version;
    }

    public function value(): string
    {
        return $this->address;
    }

    public function equals(self $other): bool
    {
        return $this->address === $other->address;
    }

    public function __toString(): string
    {
        return $this->address;
    }

    /**
     * Round-trip through the packed binary form so that every equivalent
     * spelling of one address collapses to a single representation — this is
     * what makes the (subnet_id, address) unique index meaningful.
     */
    private static function canonicalise(string $address, IpVersion $version): string
    {
        $packed = inet_pton($address);

        if ($packed === false) {
            throw InvalidIpAddressException::forAddress($address);
        }

        $canonical = inet_ntop($packed);

        if ($canonical === false) {
            throw InvalidIpAddressException::forAddress($address);
        }

        return $version === IpVersion::V6 ? strtolower($canonical) : $canonical;
    }
}
