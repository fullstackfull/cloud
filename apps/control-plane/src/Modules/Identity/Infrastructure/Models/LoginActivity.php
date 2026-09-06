<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Identity\Domain\Enums\LoginOutcome;

/**
 * Append-only authentication history.
 *
 * Rows are never updated or deleted by application code; retention is enforced
 * by a scheduled prune, not by mutation.
 *
 * @property string $id
 * @property LoginOutcome $outcome
 */
class LoginActivity extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => LoginOutcome::class,
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
