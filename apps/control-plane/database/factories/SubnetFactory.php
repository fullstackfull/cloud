<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Cidr;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpPool;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;

/**
 * @extends Factory<Subnet>
 */
class SubnetFactory extends Factory
{
    protected $model = Subnet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A /29 by default: eight addresses is small enough that a test can
        // exhaust it deliberately and still name every address it expects.
        $block = sprintf('198.51.%d.%d/29', fake()->numberBetween(0, 255), fake()->numberBetween(0, 31) * 8);

        return [
            'ip_pool_id' => IpPool::factory(),
            'network_id' => null,
            'cidr' => $block,
            'ip_version' => IpVersion::V4,
            'prefix_length' => 29,
            'gateway' => fn (array $attributes): string => self::firstHostOf((string) $attributes['cidr']),
            'is_active' => true,
        ];
    }

    /**
     * Pin the block, keeping prefix length, version and gateway consistent
     * with it — three columns that describe one fact and must not disagree.
     */
    public function forBlock(string $cidr, ?string $gateway = null): static
    {
        $block = Cidr::fromString($cidr);

        return $this->state(fn (): array => [
            'cidr' => (string) $block,
            'ip_version' => $block->version(),
            'prefix_length' => $block->prefixLength(),
            'gateway' => $gateway ?? ($block->version() === IpVersion::V4 ? self::firstHostOf($cidr) : null),
        ]);
    }

    /**
     * IPv6 is delegated as a prefix, never expanded into rows — a /64 subnet
     * exists to be handed to a service whole.
     */
    public function ipv6(string $cidr = '2001:db8:1234::/64'): static
    {
        $block = Cidr::fromString($cidr);

        return $this->state(fn (): array => [
            'cidr' => (string) $block,
            'ip_version' => IpVersion::V6,
            'prefix_length' => $block->prefixLength(),
            'gateway' => null,
        ]);
    }

    public function withoutGateway(): static
    {
        return $this->state(fn (): array => ['gateway' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    private static function firstHostOf(string $cidr): string
    {
        $block = Cidr::fromString($cidr);

        return long2ip((int) ip2long($block->networkAddress()) + 1);
    }
}
