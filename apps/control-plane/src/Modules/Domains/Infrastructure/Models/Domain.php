<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Dns\Infrastructure\Models\DnsZone;
use Lynomia\Modules\Domains\Domain\Enums\DomainState;
use Lynomia\Modules\Domains\Domain\Exceptions\IllegalDomainTransitionException;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * A name this platform holds, or is trying to.
 *
 * The state moves through {@see self::transitionTo()} and nowhere else, so
 * that "this domain is registered" is a sentence only a registrar answering
 * can cause to be written.
 *
 * The zone link points one way. A domain may know its zone; nothing in the DNS
 * module knows domains exist, because a customer may hold a name here and
 * serve its DNS elsewhere, or the reverse, and both have to go on working.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $name
 * @property string $tld
 * @property DomainState $state
 * @property string $provider
 * @property ?string $provider_reference
 * @property int $term_years
 * @property ?CarbonImmutable $registered_at
 * @property ?CarbonImmutable $expires_at
 * @property bool $auto_renew
 * @property ?bool $transfer_locked
 * @property ?list<string> $nameservers
 * @property ?string $dns_zone_id
 * @property ?CarbonImmutable $reconciled_at
 * @property ?string $review_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory, HasUlids;

    protected $table = 'domains';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => DomainState::class,
            'auto_renew' => 'boolean',
            'transfer_locked' => 'boolean',
            'nameservers' => 'array',
            'registered_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'reconciled_at' => 'immutable_datetime',
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
     * @return BelongsTo<DnsZone, $this>
     */
    public function dnsZone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    /**
     * @return HasMany<DomainContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(DomainContact::class);
    }

    /**
     * @return HasMany<DomainOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(DomainOperation::class);
    }

    /**
     * Whether this domain is close enough to its expiry to say so.
     *
     * Computed rather than stored, which is the one design decision in this
     * model worth arguing about. A stored `expiring` state would be a second
     * copy of `expires_at` that is wrong whenever the sweep maintaining it is
     * late — and the only symptom of that would be a customer not warned about
     * a domain they were about to lose. A date cannot go stale.
     */
    public function isExpiringWithin(int $days, ?CarbonImmutable $now = null): bool
    {
        if ($this->expires_at === null || ! $this->state->isHeld()) {
            return false;
        }

        $now ??= CarbonImmutable::now();

        return $this->expires_at->isAfter($now)
            && $this->expires_at->isBefore($now->addDays($days));
    }

    /**
     * Move to a state, or refuse.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transitionTo(DomainState $next, array $attributes = []): void
    {
        if (! $this->state->canBecome($next)) {
            throw IllegalDomainTransitionException::between((string) $this->getKey(), $this->state, $next);
        }

        $this->forceFill([...$attributes, 'state' => $next])->save();
    }
}
