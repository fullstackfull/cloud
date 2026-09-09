<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ResourceDriftFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Shared\Infrastructure\Casts\RedactedJsonCast;

/**
 * A disagreement between what the platform believes and what a provider
 * reports.
 *
 * One row per distinct disagreement, not one per sighting: a reconciler that
 * runs every thirty minutes would otherwise turn a single unnoticed orphan
 * into forty-eight rows a day and an alert channel nobody reads.
 *
 * Nothing in this module resolves a drift. Resolution is an operator decision,
 * recorded here with who made it.
 *
 * @property string $id
 * @property string $provider
 * @property string $resource_type
 * @property ?string $service_id
 * @property ?string $provider_reference
 * @property DriftKind $kind
 * @property DriftSeverity $severity
 * @property DriftStatus $status
 * @property ?array<string, mixed> $expected
 * @property ?array<string, mixed> $observed
 * @property int $occurrences
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 */
class ResourceDrift extends Model
{
    /** @use HasFactory<ResourceDriftFactory> */
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
        'status' => 'open',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected' => RedactedJsonCast::class,
            'observed' => RedactedJsonCast::class,
            'kind' => DriftKind::class,
            'severity' => DriftSeverity::class,
            'status' => DriftStatus::class,
            'occurrences' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isOpen(): bool
    {
        return $this->status === DriftStatus::Open;
    }
}
