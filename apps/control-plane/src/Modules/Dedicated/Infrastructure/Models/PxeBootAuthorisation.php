<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PxeBootAuthorisationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Dedicated\Domain\Enums\PxeAuthorisationStatus;
use Lynomia\Modules\Dedicated\Domain\Exceptions\PxeAuthorisationRefusedException;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Casts\RedactedJsonCast;

/**
 * One permission, for one machine, to erase itself and install an operating
 * system from the network.
 *
 * This row exists so that a reinstall is a recorded decision with an expiry
 * rather than a standing configuration. Three columns carry that weight:
 *
 *  - `expires_at` is not nullable. A permission that never lapses is a
 *    permanent invitation to reinstall, which is exactly the boot-order change
 *    the platform refuses to make;
 *  - `authorisation_reason` is not nullable either, and
 *  - `authorised_by_user_id` records who decided. When a customer's server is
 *    found reinstalled, these two are the difference between an answer and a
 *    shrug.
 *
 * `rendered_config` goes through the shared redactor on the way in. An answer
 * file legitimately contains a hashed root password and may contain a plain
 * one if a caller is careless; this column must not become the place the
 * platform keeps customers' credentials.
 *
 * @property string $id
 * @property string $dedicated_server_id
 * @property ?string $os_install_profile_id
 * @property ?string $provisioning_job_id
 * @property string $mac_address
 * @property PxeAuthorisationStatus $status
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $booted_at
 * @property ?CarbonImmutable $completed_at
 * @property ?string $authorised_by_user_id
 * @property string $authorisation_reason
 * @property ?array<string, mixed> $rendered_config
 */
class PxeBootAuthorisation extends Model
{
    /** @use HasFactory<PxeBootAuthorisationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rendered_config' => RedactedJsonCast::class,
            'status' => PxeAuthorisationStatus::class,
            'expires_at' => 'immutable_datetime',
            'booted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<DedicatedServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(DedicatedServer::class, 'dedicated_server_id');
    }

    /**
     * @return BelongsTo<OsInstallProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(OsInstallProfile::class, 'os_install_profile_id');
    }

    /**
     * @return BelongsTo<ProvisioningJob, $this>
     */
    public function provisioningJob(): BelongsTo
    {
        return $this->belongsTo(ProvisioningJob::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by_user_id');
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Whether this permission may still be acted on.
     *
     * Both halves are checked and neither is sufficient. A status alone cannot
     * authorise a boot — an authorisation left `pending` by a worker that died
     * would otherwise stand for ever — and a clock alone cannot either, or a
     * spent authorisation would be reusable until it lapsed.
     */
    public function isUsable(): bool
    {
        return $this->status->permitsBoot() && ! $this->hasExpired();
    }

    /**
     * @throws PxeAuthorisationRefusedException
     */
    public function assertUsable(): void
    {
        if ($this->hasExpired()) {
            throw PxeAuthorisationRefusedException::becauseAuthorisationExpired(
                (string) $this->getKey(),
                $this->expires_at->toIso8601String(),
            );
        }

        if (! $this->status->permitsBoot()) {
            throw PxeAuthorisationRefusedException::becauseAuthorisationIsFinished(
                (string) $this->getKey(),
                $this->status->value,
            );
        }
    }

    /**
     * Record that the machine took the network boot.
     *
     * Guarded rather than a plain setter: this is the method the boot server
     * calls when a machine asks to install, so it is the last place the
     * platform can refuse a boot that is out of time. The expiry check has to
     * live at the moment of use, not only at the moment of grant.
     *
     * @throws PxeAuthorisationRefusedException
     */
    public function markBooted(): void
    {
        $this->assertUsable();

        $this->forceFill([
            'status' => PxeAuthorisationStatus::Booted,
            'booted_at' => now(),
        ])->save();
    }

    public function markCompleted(): void
    {
        $this->forceFill([
            'status' => PxeAuthorisationStatus::Completed,
            'completed_at' => now(),
        ])->save();
    }

    public function markFailed(): void
    {
        $this->forceFill([
            'status' => PxeAuthorisationStatus::Failed,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Withdraw a permission that was granted and then could not be armed.
     *
     * Kept as a state change rather than a delete: the grant happened, and a
     * machine that was told to boot from the network and then told not to is
     * something an operator investigating a surprise reinstall needs to see.
     */
    public function revoke(): void
    {
        $this->forceFill([
            'status' => PxeAuthorisationStatus::Revoked,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Permissions that have run out of time but still say they may boot.
     *
     * This is what a reaper closes off. The status is not derived on read
     * because the boot server queries this table by MAC and status, and a
     * derived predicate cannot be indexed.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [PxeAuthorisationStatus::Pending->value, PxeAuthorisationStatus::Booted->value])
            ->where('expires_at', '<', now());
    }
}
