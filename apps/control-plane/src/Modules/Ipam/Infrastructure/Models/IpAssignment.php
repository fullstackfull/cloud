<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IpAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * An address actually configured on something.
 *
 * Assignment rows are append-only history: releasing an address stamps
 * released_at and never deletes the row, because the record of who held which
 * address at which time is the evidence every abuse report, every blocklist
 * dispute and every law-enforcement request is answered from.
 *
 * The live-assignment partial unique index (unique on ip_address_id WHERE
 * released_at IS NULL) is what keeps that history from also being a way to
 * hand one address to two services.
 *
 * @property string $id
 * @property string $ip_address_id
 * @property ?string $customer_id
 * @property ?string $service_id
 * @property ?string $assignable_type
 * @property ?string $assignable_id
 * @property bool $is_primary
 * @property ?string $mac_address
 * @property CarbonImmutable $assigned_at
 * @property ?CarbonImmutable $released_at
 */
class IpAssignment extends Model
{
    /** @use HasFactory<IpAssignmentFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'assigned_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<IpAddress, $this>
     */
    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The concrete thing wearing the address — a VM, a dedicated server, a
     * load balancer. Polymorphic because the modules that own those types are
     * not allowed to be dependencies of IPAM.
     *
     * @return MorphTo<Model, $this>
     */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isLive(): bool
    {
        return $this->released_at === null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }
}
