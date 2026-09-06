<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IpReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;

/**
 * A claim on an address held while a provisioning job runs.
 *
 * A reservation is the gap between "this address is chosen" and "this address
 * is configured on a NIC", which on a busy hypervisor is minutes. The partial
 * unique index ip_reservations_live_idx — unique on ip_address_id WHERE
 * released_at IS NULL — is what makes that gap safe: the database itself
 * refuses a second live reservation on one address, so a bug in the allocator
 * becomes a failed insert rather than two customers on one IP.
 *
 * @property string $id
 * @property string $ip_address_id
 * @property ?string $provisioning_job_id
 * @property ?string $customer_id
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $released_at
 * @property ?ReleaseReason $released_reason
 */
class IpReservation extends Model
{
    /** @use HasFactory<IpReservationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'released_reason' => ReleaseReason::class,
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

    /** Still holding the address. */
    public function isLive(): bool
    {
        return $this->released_at === null;
    }

    /**
     * Past its window. Note what this does NOT mean: an expired reservation is
     * not free to reclaim. See ReapExpiredReservations — expiry is a signal to
     * look at the job, not permission to take the address back.
     */
    public function hasElapsed(): bool
    {
        return $this->expires_at->isPast();
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
