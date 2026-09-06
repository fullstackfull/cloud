<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\ValueObjects;

use Generator;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Exceptions\AddressNotAllocatableException;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidIpAddressException;
use Stringable;

/**
 * A CIDR block, and the arithmetic the allocator needs from it.
 *
 * Two design decisions are load-bearing:
 *
 *  - the block is normalised on construction ("203.0.113.7/24" becomes
 *    "203.0.113.0/24"), because a subnet identified by one of its own hosts
 *    would produce a second, overlapping set of address rows;
 *  - v4 arithmetic is done on integers, not on strings. Every off-by-one in
 *    address maths is a real address either handed out twice or lost forever,
 *    and string comparison of dotted quads is not ordering ("10.0.0.9" sorts
 *    after "10.0.0.10").
 *
 * v6 blocks parse and answer questions about themselves, but never enumerate:
 * see hosts().
 *
 * @immutable
 */
final readonly class Cidr implements Stringable
{
    private function __construct(
        private string $networkAddress,
        private int $prefixLength,
        private IpVersion $version,
    ) {}

    public static function fromString(string $cidr): self
    {
        $parts = explode('/', trim($cidr));

        if (count($parts) !== 2) {
            throw InvalidIpAddressException::forCidr($cidr, 'expected the form <address>/<prefix length>');
        }

        [$address, $prefix] = $parts;

        if (! IpAddressValue::isValid($address)) {
            throw InvalidIpAddressException::forCidr($cidr, sprintf('"%s" is not an IP address', $address));
        }

        if (! preg_match('/^\d{1,3}$/', $prefix)) {
            throw InvalidIpAddressException::forCidr($cidr, 'the prefix length is not a number');
        }

        $parsed = IpAddressValue::fromString($address);
        $prefixLength = (int) $prefix;

        if ($prefixLength < 0 || $prefixLength > $parsed->version()->maxPrefixLength()) {
            throw InvalidIpAddressException::forCidr(
                $cidr,
                sprintf('/%d is out of range for IPv%d', $prefixLength, $parsed->version()->value),
            );
        }

        return new self(
            self::maskToNetwork($parsed, $prefixLength),
            $prefixLength,
            $parsed->version(),
        );
    }

    public function version(): IpVersion
    {
        return $this->version;
    }

    public function prefixLength(): int
    {
        return $this->prefixLength;
    }

    public function networkAddress(): string
    {
        return $this->networkAddress;
    }

    /**
     * The all-ones address of a v4 block. IPv6 has no broadcast address at
     * all — the protocol replaced broadcast with multicast — so asking for one
     * is a category error rather than a value.
     */
    public function broadcastAddress(): ?string
    {
        if ($this->version !== IpVersion::V4) {
            return null;
        }

        return long2ip($this->firstInt() + $this->size() - 1);
    }

    /**
     * Total addresses in the block, including the network and broadcast
     * addresses. Only meaningful — and only safe to call — for v4: a v6 /64
     * holds more addresses than PHP's integer can express.
     */
    public function size(): int
    {
        if ($this->version !== IpVersion::V4) {
            throw AddressNotAllocatableException::ipv6IsDelegatedNotEnumerated($this->__toString());
        }

        return 1 << (32 - $this->prefixLength);
    }

    /**
     * How many addresses in the block can be given to a host.
     */
    public function usableHostCount(): int
    {
        $size = $this->size();

        // RFC 3021: on a /31 both addresses are usable, because a two-address
        // point-to-point link has no room for — and no need of — a network and
        // a broadcast address. A /32 is a single host route. Subtracting two
        // from either would give a negative "usable" count.
        return $this->prefixLength >= 31 ? $size : $size - 2;
    }

    public function contains(string $address): bool
    {
        if (! IpAddressValue::isValid($address)) {
            return false;
        }

        $parsed = IpAddressValue::fromString($address);

        if ($parsed->version() !== $this->version) {
            return false;
        }

        return self::maskToNetwork($parsed, $this->prefixLength) === $this->networkAddress;
    }

    /**
     * Whether this address is the block's network or broadcast address — the
     * two that are structurally not hosts.
     */
    public function isInfrastructureAddress(string $address): bool
    {
        if ($this->prefixLength >= 31) {
            return false;
        }

        return $address === $this->networkAddress || $address === $this->broadcastAddress();
    }

    /**
     * Every address in the block, in ascending numeric order, lazily.
     *
     * A Generator rather than an array on purpose: a /16 is 65,536 strings,
     * and the caller (SeedSubnetAddresses) consumes this in chunks so that
     * neither PHP's heap nor a single INSERT ever holds the whole subnet.
     *
     * @return Generator<int, string>
     */
    public function addresses(): Generator
    {
        if ($this->version !== IpVersion::V4) {
            throw AddressNotAllocatableException::ipv6IsDelegatedNotEnumerated($this->__toString());
        }

        $first = $this->firstInt();
        $last = $first + $this->size() - 1;

        for ($current = $first; $current <= $last; $current++) {
            yield long2ip($current);
        }
    }

    public function __toString(): string
    {
        return $this->networkAddress.'/'.$this->prefixLength;
    }

    private function firstInt(): int
    {
        return (int) ip2long($this->networkAddress);
    }

    /**
     * Zero the host bits. For v6 this works on the 16-byte packed form, which
     * is why it is written in terms of bytes rather than integers.
     */
    private static function maskToNetwork(IpAddressValue $address, int $prefixLength): string
    {
        $packed = (string) inet_pton($address->value());
        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;
        $length = strlen($packed);

        $masked = substr($packed, 0, $fullBytes);

        if ($remainingBits > 0 && $fullBytes < $length) {
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            $masked .= chr(ord($packed[$fullBytes]) & $mask);
        }

        $masked .= str_repeat("\0", $length - strlen($masked));

        $result = inet_ntop($masked);

        if ($result === false) {
            throw InvalidIpAddressException::forAddress($address->value());
        }

        return $address->version() === IpVersion::V6 ? strtolower($result) : $result;
    }
}
