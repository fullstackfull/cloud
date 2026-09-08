<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure\Models;

use Database\Factories\SubnetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\ValueObjects\Cidr;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;

/**
 * One CIDR block, belonging to a pool and (usually) carried by a network.
 *
 * @property string $id
 * @property string $ip_pool_id
 * @property ?string $network_id
 * @property string $cidr
 * @property IpVersion $ip_version
 * @property ?string $gateway
 * @property int $prefix_length
 * @property bool $is_active
 */
class Subnet extends Model
{
    /** @use HasFactory<SubnetFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ip_version' => IpVersion::class,
            'prefix_length' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Stored normalised, so that "198.51.100.13/29" and "198.51.100.8/29" are
     * one subnet rather than two overlapping sets of address rows.
     *
     * @return Attribute<string, string>
     */
    protected function cidr(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => (string) Cidr::fromString($value),
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    protected function gateway(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): ?string => $value === null || $value === ''
                ? null
                : IpAddressValue::fromString($value)->value(),
        );
    }

    /**
     * The parsed block.
     *
     * Named block() rather than cidr() so it cannot collide with the string
     * column of the same name — an accessor and an attribute fighting over one
     * name is a bug that only shows up once something writes to it.
     */
    public function block(): Cidr
    {
        return Cidr::fromString($this->cidr);
    }

    /**
     * @return BelongsTo<IpPool, $this>
     */
    public function ipPool(): BelongsTo
    {
        return $this->belongsTo(IpPool::class);
    }

    /**
     * @return BelongsTo<Network, $this>
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * @return HasMany<IpAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /**
     * Addresses that are structurally not hosts, plus the gateway.
     *
     * The gateway belongs in this list even though nothing about the CIDR
     * makes it special: it is the one address whose loss takes the whole
     * subnet off the network rather than one customer.
     *
     * @return list<string>
     */
    public function nonHostAddresses(): array
    {
        if ($this->ip_version !== IpVersion::V4) {
            return array_filter([$this->gateway]);
        }

        $block = $this->block();

        $addresses = $block->prefixLength() >= 31
            // RFC 3021 point-to-point: both addresses carry hosts.
            ? []
            : [$block->networkAddress(), (string) $block->broadcastAddress()];

        if ($this->gateway !== null && $this->gateway !== '') {
            $addresses[] = $this->gateway;
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
