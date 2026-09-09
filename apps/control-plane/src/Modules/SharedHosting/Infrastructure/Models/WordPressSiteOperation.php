<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationKind;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressOperationState;
use Lynomia\Modules\SharedHosting\Domain\Enums\WordPressPushScope;

/**
 * One copy or push, from the ask to the toolkit's answer.
 *
 * @property string $id
 * @property string $customer_id
 * @property string $wordpress_site_id
 * @property ?string $target_site_id
 * @property WordPressOperationKind $kind
 * @property WordPressOperationState $state
 * @property ?WordPressPushScope $scope
 * @property ?array<string, mixed> $impact
 * @property ?string $provisioning_job_id
 * @property ?string $failure_reason
 * @property ?string $requested_by_user_id
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class WordPressSiteOperation extends Model
{
    use HasUlids;

    protected $table = 'wordpress_site_operations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WordPressOperationKind::class,
            'state' => WordPressOperationState::class,
            'scope' => WordPressPushScope::class,
            'impact' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WordPressSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(WordPressSite::class, 'wordpress_site_id');
    }

    /**
     * @return BelongsTo<WordPressSite, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(WordPressSite::class, 'target_site_id');
    }
}
