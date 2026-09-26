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

    // ---- overlap: whether two blocks share an address ----------------------

    #[Test]
    #[DataProvider('pairsOfBlocks')]
    public function two_blocks_overlap_exactly_when_they_share_an_address(string $a, string $b, bool $overlap): void
    {
        $first = Cidr::fromString($a);
        $second = Cidr::fromString($b);

        // Symmetric, both ways round, or the answer depends on which block
        // happened to be registered first.
        $this->assertSame($overlap, $first->overlaps($second), "{$a} against {$b}");
        $this->assertSame($overlap, $second->overlaps($first), "{$b} against {$a}");
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function pairsOfBlocks(): array
    {
        return [
            'the same block' => ['203.0.113.0/24', '203.0.113.0/24', true],
            'the same block, spelled from a host' => ['203.0.113.0/24', '203.0.113.7/24', true],
            'a half inside the whole' => ['203.0.113.0/24', '203.0.113.0/25', true],
            'the upper half inside the whole' => ['203.0.113.0/24', '203.0.113.128/25', true],
            'a host route inside a /16' => ['10.1.0.0/16', '10.1.2.3/32', true],
            'the last address of a block' => ['10.0.0.0/8', '10.255.255.255/32', true],
            'everything overlaps the default route' => ['0.0.0.0/0', '198.51.100.7/32', true],
            'adjacent halves' => ['203.0.113.0/25', '203.0.113.128/25', false],
            'neighbouring /8s' => ['10.0.0.0/8', '11.0.0.0/8', false],
            'the address just past a block' => ['10.0.0.0/8', '11.0.0.0/32', false],
            'disjoint' => ['198.51.100.0/24', '203.0.113.0/24', false],
            'v6 prefix inside a v6 prefix' => ['2001:db8::/32', '2001:db8:1234::/48', true],
            'v6 host inside a v6 /64' => ['2001:db8:1:2::/64', '2001:db8:1:2::abcd/128', true],
            'adjacent v6 /64s' => ['2001:db8:1:2::/64', '2001:db8:1:3::/64', false],
            'v6 blocks differing in the last bit of the prefix' => ['2001:db8::/33', '2001:db8:8000::/33', false],
            'everything v6 overlaps ::/0' => ['::/0', 'fd12:3456::/48', true],
        ];
    }

    #[Test]
    public function blocks_of_two_families_never_overlap(): void
    {
        // ::/0 contains every v6 address and 0.0.0.0/0 every v4 one. They are
        // different address spaces, not a containment, whichever way round.
        $this->assertFalse(Cidr::fromString('0.0.0.0/0')->overlaps(Cidr::fromString('::/0')));
        $this->assertFalse(Cidr::fromString('::/0')->overlaps(Cidr::fromString('0.0.0.0/0')));
        $this->assertFalse(Cidr::fromString('10.0.0.0/8')->overlaps(Cidr::fromString('::a00:0/104')));
    }

    // ---- reuse: whether two buildings may each hold a block ----------------

    #[Test]
    #[DataProvider('blocksAndWhetherTheyMayBeReused')]
    public function a_block_is_locally_reusable_only_when_it_is_wholly_inside_space_designated_for_reuse(
        string $cidr,
        bool $reusable,
    ): void {
        $this->assertSame($reusable, Cidr::fromString($cidr)->isLocallyReusable(), $cidr);
    }

    /**
     * Every designated range appears whole and as a block inside it, and each
     * is fenced by the blocks just outside it on either side, so that a range
     * drawn one bit too wide or too narrow reddens a row rather than passing.
     *
     * @return array<string, array{string, bool}>
     */
    public static function blocksAndWhetherTheyMayBeReused(): array
    {
        return [
            // RFC 1918.
            '10/8 whole' => ['10.0.0.0/8', true],
            'inside 10/8' => ['10.20.30.0/24', true],
            'just below 10/8' => ['9.255.255.0/24', false],
            'just above 10/8' => ['11.0.0.0/24', false],
            '172.16/12 whole' => ['172.16.0.0/12', true],
            'the top of 172.16/12' => ['172.31.255.0/24', true],
            'just below 172.16/12' => ['172.15.255.0/24', false],
            'just above 172.16/12' => ['172.32.0.0/24', false],
            '192.168/16 whole' => ['192.168.0.0/16', true],
            'inside 192.168/16' => ['192.168.5.0/24', true],
            'just below 192.168/16' => ['192.167.255.0/24', false],
            'just above 192.168/16' => ['192.169.0.0/24', false],

            // RFC 6598 shared address space, reused behind every CGNAT.
            '100.64/10 whole' => ['100.64.0.0/10', true],
            'the top of 100.64/10' => ['100.127.255.0/24', true],
            'just below 100.64/10' => ['100.63.255.0/24', false],
            'just above 100.64/10' => ['100.128.0.0/24', false],

            // RFC 3927 link-local and RFC 1122 loopback: meaningful only on
            // one link or one host by definition.
            '169.254/16 whole' => ['169.254.0.0/16', true],
            'just above 169.254/16' => ['169.255.0.0/24', false],
            'just below 169.254/16' => ['169.253.255.0/24', false],
            '127/8 whole' => ['127.0.0.0/8', true],
            'just above 127/8' => ['128.0.0.0/24', false],
            'just below 127/8' => ['126.255.255.0/24', false],

            // Documentation space is not designated for reuse. It is not
            // routed, which is a different question: two buildings holding
            // 203.0.113.0/24 hand out one address string twice.
            'TEST-NET-1' => ['192.0.2.0/24', false],
            'TEST-NET-2' => ['198.51.100.0/24', false],
            'TEST-NET-3' => ['203.0.113.0/24', false],
            'public' => ['9.0.0.0/8', false],
            'multicast' => ['224.0.0.0/4', false],
            'the default route' => ['0.0.0.0/0', false],

            // Straddles: half inside a reusable range is not inside it.
            '10/7 straddles 10/8 and the public 11/8' => ['10.0.0.0/7', false],
            '172.0/11 straddles 172.16/12' => ['172.0.0.0/11', false],
            '192.168/15 straddles 192.168/16 and the public 192.169/16' => ['192.168.0.0/15', false],
            '100.0/9 straddles 100.64/10' => ['100.0.0.0/9', false],
            '126/7 straddles 127/8' => ['126.0.0.0/7', false],
            '169.254/15 straddles 169.254/16' => ['169.254.0.0/15', false],

            // RFC 4193: only L=1, fd00::/8, is locally assigned. fc00::/8 is
            // held for a centrally assigned scheme whose prefixes would be
            // unique, so it is not space two buildings may both hold.
            'fd00::/8 whole' => ['fd00::/8', true],
            'inside fd00::/8' => ['fd12:3456:789a::/48', true],
            'fc00::/8, held for central assignment' => ['fc00::/8', false],
            'fc00::/7 straddles fd00::/8' => ['fc00::/7', false],
            'just above fd00::/8' => ['fe00::/8', false],

            // RFC 4291 section 2.5.6: link-local unicast is fe80::/64. The
            // rest of fe80::/10 is not link-local.
            'fe80::/64 whole' => ['fe80::/64', true],
            'a host on fe80::/64' => ['fe80::1234/128', true],
            'fe80::/10 is wider than link-local' => ['fe80::/10', false],
            'fe80:0:0:1::/64 is inside fe80::/10 and not link-local' => ['fe80:0:0:1::/64', false],

            // v6 loopback, and its neighbours.
            '::1/128' => ['::1/128', true],
            '::2/128' => ['::2/128', false],
            '::/127 straddles ::1' => ['::/127', false],

            'v6 documentation' => ['2001:db8::/32', false],
            'v6 global unicast' => ['2a00:1450::/32', false],
            'v6 multicast' => ['ff02::/16', false],
            'the v6 default route' => ['::/0', false],
        ];
    }
}
