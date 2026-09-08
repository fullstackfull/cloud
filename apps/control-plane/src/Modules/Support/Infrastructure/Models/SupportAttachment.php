<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file somebody attached to a message.
 *
 * The stored `path` is generated, never built from `original_name`: a filename
 * from a customer is attacker-controlled, and a path built from one is a
 * directory traversal waiting for somebody to forget to sanitise it.
 *
 * @property string $id
 * @property string $message_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum
 * @property CarbonImmutable $created_at
 */
class SupportAttachment extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'support_attachments';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /**
     * @return BelongsTo<SupportMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'message_id');
    }
}
