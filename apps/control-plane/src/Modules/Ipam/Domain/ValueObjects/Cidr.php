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
    /**
     * Space the address plan designates for reuse: blocks that any number of
     * independent networks may each hold at once and mean different wires.
     *
     * The list is of *designation*, not of routing. Documentation space
     * (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24) is not routed, and it
     * is deliberately absent: two buildings each holding 203.0.113.0/24 still
     * hand out one address string twice, which is the harm. "Would a transit
     * provider carry this packet" and "may two buildings each hold this block"
     * are different questions, and only the second is asked here.
     *
     * Anything not listed is treated as unique in the world, and that default
     * is the safe direction: a range wrongly called reusable is an address
     * handed to two customers, while one wrongly called unique is a refusal an
     * operator can read.
     *
     *  - 10/8, 172.16/12, 192.168/16: RFC 1918 private space.
     *  - 100.64/10: RFC 6598 shared address space, reused behind every
     *    carrier-grade NAT.
     *  - 169.254/16: RFC 3927 link-local; meaningful only on one link.
     *  - 127/8 and ::1/128: loopback; meaningful only on one host.
     *  - fd00::/8: RFC 4193 unique local with L=1, the locally assigned half.
     *    fc00::/8, the other half of fc00::/7, is held for a centrally
     *    assigned scheme whose prefixes would be unique, so it is not here.
     *  - fe80::/64: link-local unicast as RFC 4291 section 2.5.6 lays it out.
     *    fe80::/10 is the type prefix and is wider than that.
     */
    private const array LOCALLY_REUSABLE = [
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '100.64.0.0/10',
        '169.254.0.0/16',
        '127.0.0.0/8',
        'fd00::/8',
        'fe80::/64',
        '::1/128',
    ];

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
     * Whether the two blocks share at least one address.
     *
     * Two CIDR blocks either nest or are disjoint — they cannot partly
     * overlap — so they share an address exactly when they agree on the bits
     * the shorter prefix fixes. Blocks of different families never overlap:
     * ::/0 and 0.0.0.0/0 are two address spaces, not a containment. (The
     * masked comparison would reach the same answer on its own, because a v4
     * and a v6 network address never print alike; the check states it rather
     * than leaving it to how addresses are printed.)
     */
    public function overlaps(self $other): bool
    {
        if ($this->version !== $other->version) {
            return false;
        }

        $shorter = min($this->prefixLength, $other->prefixLength);

        return self::maskToNetwork(IpAddressValue::fromString($this->networkAddress), $shorter)
            === self::maskToNetwork(IpAddressValue::fromString($other->networkAddress), $shorter);
    }

    /**
     * Whether every address of $other is inside this block.
     *
     * A block of the other family is never inside this one. (As in
     * overlaps(), the masked comparison would reach that on its own — the
     * masked address keeps $other's family and is compared with this block's
     * network address, and a v4 and a v6 address never print alike; the
     * check states it rather than leaving it to how addresses are printed.)
     */
    public function encloses(self $other): bool
    {
        return $this->version === $other->version
            && $this->prefixLength <= $other->prefixLength
            && self::maskToNetwork(IpAddressValue::fromString($other->networkAddress), $this->prefixLength)
                === $this->networkAddress;
    }

    /**
     * Whether two buildings may each hold this block and mean different
     * wires — see LOCALLY_REUSABLE for which space that is and why.
     *
     * Whole containment, not overlap: a block is reusable only when it is
     * entirely inside one designated range. 10.0.0.0/7 is half RFC 1918 and
     * half the entirely public 11.0.0.0/8, and a block with any unique space
     * in it is unique.
     */
    public function isLocallyReusable(): bool
    {
        foreach (self::reusableRanges() as $range) {
            if ($range->encloses($this)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<self>
     */
    private static function reusableRanges(): array
    {
        return array_map(static fn (string $range): self => self::fromString($range), self::LOCALLY_REUSABLE);
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
