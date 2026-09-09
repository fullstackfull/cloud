<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LicenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Providers\Domain\Enums\LicenceState;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * A commercial licence Lynomia holds, and when it stops being true.
 *
 * An inventory, not an activation engine: nothing here talks to a vendor's
 * licensing server or manipulates a key. It records what was bought, what it
 * covers and when it lapses, so that "cPanel stopped working" can be answered
 * with "the licence expired on Tuesday" rather than investigated as an outage.
 *
 * A licence key that is itself sensitive is a credential reference. The
 * external_reference here is an order number or account id — the thing a
 * person quotes to the vendor's support desk, which is not a secret.
 *
 * @property string $id
 * @property string $product
 * @property ?string $licence_type
 * @property ?int $seats
 * @property ?string $external_reference
 * @property ?string $notes
 * @property ?string $invalidated_reason
 * @property ?CarbonImmutable $starts_on
 * @property ?CarbonImmutable $renews_on
 * @property ?CarbonImmutable $state_changed_at
 * @property ?CarbonImmutable $renewed_at
 * @property ?CarbonImmutable $invalidated_at
 * @property ?CarbonImmutable $created_at
 * @property-read ?int $provider_instances_count
 * @property DeploymentEnvironment $environment
 * @property LicenceState $state
 * @property ?CarbonImmutable $expires_on
 */
class Licence extends Model
{
    /** @use HasFactory<LicenceFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * The state a row has before anything happens to it.
     *
     * This duplicates the column default on purpose. A default declared only
     * in the database applies during the INSERT and not to the model object
     * that create() hands back, so a caller that renders its own result reads
     * null for a column the table will happily report a value for one query
     * later. Declared here, every creation path starts in the same state,
     * including the ones written after this comment.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'state' => 'unknown',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => DeploymentEnvironment::class,
            'state' => LicenceState::class,
            'starts_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'renews_on' => 'immutable_date',
            'seats' => 'integer',
            'state_changed_at' => 'immutable_datetime',
            'renewed_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ManagedServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    /**
     * @return BelongsTo<CredentialReference, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(CredentialReference::class, 'credential_reference_id');
    }

    /**
     * Days until it lapses, or null when it does not.
     *
     * Negative when it already has, so that "expired eleven days ago" is
     * sayable — an operator needs the number as much as the fact.
     */
    /**
     * @return HasMany<ProviderInstance, $this>
     */
    public function providerInstances(): HasMany
    {
        return $this->hasMany(ProviderInstance::class, 'licence_id');
    }

    public function daysRemaining(?CarbonImmutable $now = null): ?int
    {
        if ($this->expires_on === null) {
            return null;
        }

        return (int) ($now ?? CarbonImmutable::now())->startOfDay()->diffInDays($this->expires_on, false);
    }

    /**
     * The state the dates say it is in, whatever the column says.
     *
     * Computed rather than trusted, because a licence expires whether or not
     * anything ran a sweep that day. The sweep writes the column so that
     * queries and alerts are cheap; this is what the sweep computes, and what
     * a screen should show if the two ever disagree.
     */
    public function stateFromDates(int $expiringWithinDays = 30, ?CarbonImmutable $now = null): LicenceState
    {
        if ($this->state === LicenceState::NotRequired || $this->state === LicenceState::Invalid) {
            return $this->state;
        }

        $today = ($now ?? CarbonImmutable::now())->startOfDay();

        // Bought but not yet in force. The calendar decides this too, and a
        // licence whose start is tomorrow permits nothing today.
        if ($this->starts_on !== null && $this->starts_on->startOfDay()->isAfter($today)) {
            return LicenceState::Pending;
        }

        $remaining = $this->daysRemaining($now);

        if ($remaining === null) {
            return $this->state === LicenceState::Missing ? LicenceState::Missing : $this->state;
        }

        if ($remaining < 0) {
            return LicenceState::Expired;
        }

        if ($remaining <= $expiringWithinDays) {
            return LicenceState::Expiring;
        }

        return LicenceState::Active;
    }
}
