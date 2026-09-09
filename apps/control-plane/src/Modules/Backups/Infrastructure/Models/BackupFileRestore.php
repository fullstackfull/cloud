<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Backups\Domain\Enums\FileRestoreState;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Named paths from one backup, being put back into the machine.
 *
 * @property string $id
 * @property string $backup_id
 * @property string $customer_id
 * @property string $service_id
 * @property string $virtual_machine_id
 * @property FileRestoreState $state
 * @property string $node_name
 * @property list<string> $paths
 * @property int $path_count
 * @property ?string $provider_task_id
 * @property ?string $failure_reason
 * @property ?string $requested_by_user_id
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property ?CarbonImmutable $last_polled_at
 * @property int $poll_count
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class BackupFileRestore extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => FileRestoreState::class,
            'paths' => 'array',
            'path_count' => 'integer',
            'poll_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'last_polled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAwaitingProvider(Builder $query): Builder
    {
        return $query
            ->whereIn('state', [FileRestoreState::Requested->value, FileRestoreState::Running->value])
            ->whereNotNull('provider_task_id');
    }

    /**
     * @return BelongsTo<Backup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<VirtualMachine, $this>
     */
    public function virtualMachine(): BelongsTo
    {
        return $this->belongsTo(VirtualMachine::class);
    }
}
