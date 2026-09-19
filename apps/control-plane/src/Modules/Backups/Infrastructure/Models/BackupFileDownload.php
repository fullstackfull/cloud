<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One short-lived, single-use permission to download one file out of one
 * backup. The token itself is not here — only its hash — so a copy of
 * this table is not a copy of anybody's download links.
 *
 * @property string $id
 * @property string $backup_id
 * @property string $customer_id
 * @property string $path
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $used_at
 * @property ?string $issued_by_user_id
 * @property CarbonImmutable $created_at
 */
class BackupFileDownload extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Backup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }
}
