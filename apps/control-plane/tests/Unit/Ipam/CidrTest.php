<?php

declare(strict_types=1);

namespace Tests\Unit\Ipam;

use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Exceptions\AddressNotAllocatableException;
use Lynomia\Modules\Ipam\Domain\Exceptions\InvalidIpAddressException;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Cidr;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Address arithmetic is tested on its own because every mistake in it is
 * either an address handed out twice or a block of addresses lost silently,
 * and neither is visible until a customer's machine cannot reach the network.
 */
final class CidrTest extends TestCase
{
    #[Test]
    public function it_expands_a_slash_29_into_eight_addresses(): void
    {
        $block = Cidr::fromString('198.51.100.8/29');

        $this->assertSame(8, $block->size());
        // Six hosts: eight addresses less the network and the broadcast.
        $this->assertSame(6, $block->usableHostCount());
        $this->assertSame([
            '198.51.100.8', '198.51.100.9', '198.51.100.10', '198.51.100.11',
            '198.51.100.12', '198.51.100.13', '198.51.100.14', '198.51.100.15',
        ], iterator_to_array($block->addresses()));
    }

    #[Test]
    public function it_normalises_a_block_written_from_one_of_its_hosts(): void
    {
        // Operators paste "the subnet my gateway is on". Left unnormalised
        // that would seed a second, overlapping set of address rows.
        $block = Cidr::fromString('198.51.100.13/29');

        $this->assertSame('198.51.100.8/29', (string) $block);
        $this->assertSame('198.51.100.8', $block->networkAddress());
        $this->assertSame('198.51.100.15', $block->broadcastAddress());
    }

    #[Test]
    public function it_identifies_the_network_and_broadcast_addresses(): void
    {
        $block = Cidr::fromString('10.20.30.0/24');

        $this->assertTrue($block->isInfrastructureAddress('10.20.30.0'));
        $this->assertTrue($block->isInfrastructureAddress('10.20.30.255'));
        $this->assertFalse($block->isInfrastructureAddress('10.20.30.1'));
    }

    #[Test]
    public function a_slash_31_has_no_network_or_broadcast_address(): void
    {
        // RFC 3021: both addresses of a point-to-point link carry hosts.
        // Subtracting two here would report a usable count of zero for a link
        // that works perfectly well.
        $block = Cidr::fromString('192.0.2.0/31');

        $this->assertSame(2, $block->size());
        $this->assertSame(2, $block->usableHostCount());
        $this->assertFalse($block->isInfrastructureAddress('192.0.2.0'));
    }

    #[Test]
    public function it_knows_which_addresses_it_contains(): void
    {
        $block = Cidr::fromString('198.51.100.8/29');

        $this->assertTrue($block->contains('198.51.100.9'));
        $this->assertFalse($block->contains('198.51.100.16'));
        // A v6 address is not in a v4 block, however the bits are read.
        $this->assertFalse($block->contains('2001:db8::1'));
        $this->assertFalse($block->contains('not-an-address'));
    }

    #[Test]
    public function it_refuses_to_enumerate_an_ipv6_block(): void
    {
        $block = Cidr::fromString('2001:db8:1234::/64');

        $this->assertSame(IpVersion::V6, $block->version());
        $this->assertFalse($block->version()->isEnumerable());
        $this->assertNull($block->broadcastAddress());

        $this->expectException(AddressNotAllocatableException::class);

        iterator_to_array($block->addresses());
    }

    #[Test]
    public function it_normalises_an_ipv6_block_to_its_prefix(): void
    {
        $block = Cidr::fromString('2001:0DB8:1234:5678:9ABC::1/64');

        $this->assertSame('2001:db8:1234:5678::/64', (string) $block);
    }

    #[Test]
    #[DataProvider('malformedBlocks')]
    public function it_rejects_a_malformed_block(string $cidr): void
    {
        $this->expectException(InvalidIpAddressException::class);

        Cidr::fromString($cidr);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function malformedBlocks(): array
    {
        return [
            'no prefix' => ['198.51.100.0'],
            'prefix out of range' => ['198.51.100.0/33'],
            'octet out of range' => ['198.51.100.256/24'],
            'not an address' => ['banana/24'],
            'non numeric prefix' => ['198.51.100.0/xx'],
            'empty' => [''],
        ];
    }

    #[Test]
    public function an_address_is_validated_and_canonicalised(): void
    {
        $this->assertSame('198.51.100.9', IpAddressValue::fromString(' 198.51.100.9 ')->value());
        $this->assertSame('2001:db8::1', IpAddressValue::fromString('2001:0DB8:0000::1')->value());
        $this->assertSame(IpVersion::V4, IpAddressValue::fromString('10.0.0.1')->version());
        $this->assertSame(IpVersion::V6, IpAddressValue::fromString('::1')->version());
    }

    #[Test]
    #[DataProvider('malformedAddresses')]
    public function it_rejects_a_malformed_address(string $address): void
    {
        $this->expectException(InvalidIpAddressException::class);

        IpAddressValue::fromString($address);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function malformedAddresses(): array
    {
        return [
            'octet out of range' => ['10.0.0.256'],
            // Leading zeroes are read as octal by some resolvers, so "010"
            // is not a spelling of 10 — it is a different address.
            'leading zero' => ['010.0.0.1'],
            'truncated' => ['10.0.0'],
            'words' => ['gateway'],
        ];
    }
}
