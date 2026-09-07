<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProvisioningJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Shared\Infrastructure\Casts\RedactedJsonCast;

/**
 * One unit of provisioning work, and everything needed to survive it failing.
 *
 * Two columns carry more weight than the rest:
 *
 *  - idempotency_key is unique, and is why a double-clicked purchase, a
 *    redelivered webhook and a retried queue message converge on one server
 *    instead of three;
 *  - remote_job_id is the provider's own identifier for the work, written the
 *    moment the provider hands it over. Without it a timeout is unrecoverable:
 *    the platform cannot tell whether the resource exists, and every recovery
 *    is a guess that risks creating a second one.
 *
 * @property string $id
 * @property ?string $service_id
 * @property string $idempotency_key
 * @property ProvisioningJobKind $kind
 * @property string $provider
 * @property ProvisioningJobStatus $status
 * @property ?string $remote_job_id
 * @property int $attempts
 * @property int $max_attempts
 * @property int $timeout_seconds
 * @property array<string, mixed> $payload
 * @property ?array<string, mixed> $result
 * @property ?FailureClass $failure_class
 * @property ?string $last_error
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?CarbonImmutable $next_attempt_at
 */
class ProvisioningJob extends Model
{
    /** @use HasFactory<ProvisioningJobFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => RedactedJsonCast::class,
            'result' => RedactedJsonCast::class,
            'kind' => ProvisioningJobKind::class,
            'status' => ProvisioningJobStatus::class,
            'failure_class' => FailureClass::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'timeout_seconds' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * The attempt log.
     *
     * Not named attempts(): that is the counter column, and a relation of the
     * same name would shadow it — reading $job->attempts would return a
     * collection in some code paths and an integer in others.
     *
     * @return HasMany<ProvisioningAttempt, $this>
     */
    public function attemptRecords(): HasMany
    {
        return $this->hasMany(ProvisioningAttempt::class);
    }

    /**
     * Publish the provider's job id the instant it is known.
     *
     * This is a targeted UPDATE rather than save(), and it is called from
     * inside a handler mid-flight, because the whole value of the column is
     * that it survives what happens next. Waiting for the call to return, or
     * folding it into the settle transaction, would mean the one crash it
     * exists to protect against is the one crash during which it is lost.
     */
    public function recordRemoteJobId(string $remoteJobId): void
    {
        if ($remoteJobId === '' || $this->remote_job_id === $remoteJobId) {
            return;
        }

        $this->remote_job_id = $remoteJobId;

        static::query()
            ->whereKey($this->getKey())
            ->update(['remote_job_id' => $remoteJobId, 'updated_at' => now()]);

        // The attribute is now clean: a later save() of unrelated changes must
        // not rewrite a column another worker may since have corrected.
        $this->syncOriginalAttribute('remote_job_id');
    }

    /**
     * Whether the engine still has an attempt left to spend on this job.
     */
    public function hasAttemptsRemaining(): bool
    {
        return $this->attempts < $this->max_attempts;
    }

    /**
     * The moment after which the platform stops waiting for this attempt.
     */
    public function deadline(): ?CarbonImmutable
    {
        return $this->started_at?->addSeconds($this->timeout_seconds);
    }

    /**
     * Jobs a worker has been sitting on for longer than they were given.
     *
     * The comparison is done in SQL against each row's own timeout_seconds so
     * that a dedicated server install and a power-on are not held to the same
     * clock, and so that the sweeper never has to load the table to filter it.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStale(Builder $query): Builder
    {
        return $query
            ->where('status', ProvisioningJobStatus::Running->value)
            ->whereNotNull('started_at')
            ->whereRaw("started_at + (timeout_seconds * interval '1 second') < now()");
    }
}
