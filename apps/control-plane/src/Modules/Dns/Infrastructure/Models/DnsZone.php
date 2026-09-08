<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DnsZoneFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Dns\Domain\Enums\DnsState;
use Lynomia\Modules\Dns\Domain\Exceptions\IllegalDnsTransitionException;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * A zone this platform has been asked to hold.
 *
 * The state moves through {@see self::transitionTo()} and nowhere else, so
 * that "this zone is live" is a sentence only a provider answering can cause
 * to be written.
 *
 * @property string $id
 * @property string $customer_id
 * @property ?string $service_id
 * @property string $name
 * @property DnsState $state
 * @property string $provider
 * @property ?string $provider_zone_id
 * @property ?list<string> $nameservers
 * @property ?string $created_by_user_id
 * @property ?string $failure_reason
 * @property ?CarbonImmutable $last_synced_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class DnsZone extends Model
{
    /** @use HasFactory<DnsZoneFactory> */
    use HasFactory, HasUlids;

    protected $table = 'dns_zones';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => DnsState::class,
            'nameservers' => 'array',
            'last_synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<DnsRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class, 'dns_zone_id');
    }

    /**
     * The records a customer is shown: everything that is not already gone.
     *
     * A deleted row is kept for the audit trail and for reconciliation, and
     * showing it would mean a customer watching a list where removing a record
     * makes it stay.
     *
     * @return HasMany<DnsRecord, $this>
     */
    public function liveRecords(): HasMany
    {
        return $this->records()->where('state', '!=', DnsState::Deleted->value);
    }

    /**
     * @param  array<string, mixed>  $attributes  Written in the same statement as
     *                                            the state, so a reason and the
     *                                            state it explains never land
     *                                            separately.
     *
     * @throws IllegalDnsTransitionException
     */
    public function transitionTo(DnsState $next, array $attributes = []): void
    {
        if (! $this->state->canBecome($next)) {
            throw IllegalDnsTransitionException::between((string) $this->getKey(), $this->state, $next);
        }

        $this->forceFill([...$attributes, 'state' => $next])->save();
    }
}
