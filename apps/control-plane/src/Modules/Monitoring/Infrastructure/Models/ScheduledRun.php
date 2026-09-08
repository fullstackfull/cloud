<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The last thing each scheduled command did.
 *
 * @property string $id
 * @property string $command
 * @property ?CarbonImmutable $last_ran_at
 * @property ?CarbonImmutable $last_succeeded_at
 * @property ?CarbonImmutable $last_failed_at
 * @property int $consecutive_failures
 * @property ?string $last_failure
 * @property ?int $last_runtime_ms
 */
class ScheduledRun extends Model
{
    use HasUlids;

    protected $table = 'scheduled_runs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_ran_at' => 'immutable_datetime',
            'last_succeeded_at' => 'immutable_datetime',
            'last_failed_at' => 'immutable_datetime',
        ];
    }
}
