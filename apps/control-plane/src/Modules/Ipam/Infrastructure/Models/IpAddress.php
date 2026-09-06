<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IpAddressFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\ValueObjects\IpAddressValue;

/**
 * One allocatable address.
 *
 * The status column is the allocator's only source of truth about whether this
 * address may be handed out, and it is only ever changed inside a transaction
 * that holds a row lock on this row. Nothing outside Domain/Services writes
 * it.
 *
 * @property string $id
 * @property string $subnet_id
 * @property string $address
 * @property IpVersion $ip_version
 * @property IpAddressStatus $status
 * @property ?CarbonImmutable $quarantined_until
 * @property ?ReleaseReason $quarantine_reason
 * @property ?string $notes
 */
class IpAddress extends Model
{
    /** @use HasFactory<IpAddressFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ip_version' => IpVersion::class,
            'status' => IpAddressStatus::class,
            'quarantine_reason' => ReleaseReason::class,
            'quarantined_until' => 'immutable_datetime',
        ];
    }

    /**
     * Validated and canonicalised on the way in.
     *
     * The column is a string, and a string column will happily accept
     * "10.0.0.256" or "010.0.0.1" — rows that match no allocator query and
     * that a hypervisor refuses hours later, in the middle of a build. Doing
     * it here rather than at each call site means there is no call site left
     * to forget.
     *
     * @return Attribute<string, string>
     */
    protected function address(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): string => IpAddressValue::fromString($value)->value(),
        );
    }

    /**
     * @return BelongsTo<Subnet, $this>
     */
    public function subnet(): BelongsTo
    {
        return $this->belongsTo(Subnet::class);
    }

    /**
     * @return HasMany<IpReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(IpReservation::class);
    }

    /**
     * Every assignment this address has ever had, current and historic.
     *
     * Rows are never deleted from this relation. "Who had 203.0.113.10 on the
     * 4th of March" is the first question asked when an abuse report or a law
     * enforcement request arrives, and it is unanswerable from a table that
     * only keeps the present.
     *
     * @return HasMany<IpAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(IpAssignment::class);
    }

    /**
     * @return HasOne<ReverseDnsRecord, $this>
     */
    public function reverseDnsRecord(): HasOne
    {
        return $this->hasOne(ReverseDnsRecord::class);
    }

    public function isAllocatable(): bool
    {
        return $this->status->isAllocatable();
    }

    /**
     * Whether the quarantine window has elapsed. A quarantined address with no
     * quarantined_until is a bug elsewhere; it is treated as still serving,
     * because releasing it early is the outcome quarantine exists to prevent.
     */
    public function quarantineHasElapsed(): bool
    {
        if ($this->status !== IpAddressStatus::Quarantined) {
            return false;
        }

        return $this->quarantined_until !== null
            && $this->quarantined_until->isPast();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', IpAddressStatus::Available);
    }
}
