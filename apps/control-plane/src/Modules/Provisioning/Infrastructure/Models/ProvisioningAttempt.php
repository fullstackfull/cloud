<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProvisioningAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Shared\Infrastructure\Casts\RedactedJsonCast;

/**
 * One recorded call to a provider.
 *
 * The row is written before the call is made and updated when it returns, so
 * an attempt exists even for the call that killed the worker. That is the
 * difference between "this job failed three times" and a support ticket nobody
 * can reconstruct.
 *
 * The table has no updated_at: an attempt is a fact about a moment, and a fact
 * that appears to have been edited later is a fact nobody trusts.
 *
 * @property string $id
 * @property string $provisioning_job_id
 * @property int $attempt_number
 * @property ProvisioningJobStatus $status
 * @property ?string $remote_job_id
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?array<string, mixed> $response_metadata
 * @property ?int $duration_ms
 * @property CarbonImmutable $created_at
 */
class ProvisioningAttempt extends Model
{
    /** @use HasFactory<ProvisioningAttemptFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_metadata' => RedactedJsonCast::class,
            'status' => ProvisioningJobStatus::class,
            'attempt_number' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProvisioningJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ProvisioningJob::class, 'provisioning_job_id');
    }
}
