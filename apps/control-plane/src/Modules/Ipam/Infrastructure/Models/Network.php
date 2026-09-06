<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure\Models;

use Database\Factories\NetworkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Ipam\Domain\Enums\NetworkPurpose;

/**
 * A layer-2 broadcast domain inside a datacenter: a VLAN and the bridge that
 * carries it.
 *
 * A network is where packets go; a subnet is which addresses are legal there.
 * They are separate rows because the mapping is not one to one — one VLAN
 * routinely carries several subnets as a pool is extended.
 *
 * @property string $id
 * @property string $datacenter_id
 * @property string $slug
 * @property NetworkPurpose $purpose
 * @property ?int $vlan_id
 * @property bool $is_customer_facing
 * @property bool $is_active
 */
class Network extends Model
{
    /** @use HasFactory<NetworkFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => NetworkPurpose::class,
            'vlan_id' => 'integer',
            'is_customer_facing' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Subnet, $this>
     */
    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    /**
     * Whether a customer VM may legitimately be attached here. Both flags have
     * to agree: a management network is never customer facing however the
     * boolean was set, and a disabled network takes nothing new.
     */
    public function acceptsCustomerAttachments(): bool
    {
        return $this->is_active
            && $this->is_customer_facing
            && $this->purpose->isCustomerAttachable();
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
