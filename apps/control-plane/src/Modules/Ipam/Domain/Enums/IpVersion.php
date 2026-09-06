<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Enums;

/**
 * The two address families, backed by the integer stored in ip_version.
 *
 * The distinction is not cosmetic: v4 is allocated one address at a time out
 * of an enumerated pool, v6 is delegated as a prefix per service. Everything
 * in this module that branches on version is branching on that difference —
 * see SeedSubnetAddresses for why a /64 is never expanded into rows.
 */
enum IpVersion: int
{
    case V4 = 4;
    case V6 = 6;

    public static function ofAddress(string $address): self
    {
        return str_contains($address, ':') ? self::V6 : self::V4;
    }

    /** The widest prefix length this family permits. */
    public function maxPrefixLength(): int
    {
        return match ($this) {
            self::V4 => 32,
            self::V6 => 128,
        };
    }

    /**
     * Whether a subnet of this family may be expanded into one row per
     * address. Only v4 can: the smallest routed v6 subnet, a /64, holds
     * 18,446,744,073,709,551,616 addresses.
     */
    public function isEnumerable(): bool
    {
        return $this === self::V4;
    }
}
