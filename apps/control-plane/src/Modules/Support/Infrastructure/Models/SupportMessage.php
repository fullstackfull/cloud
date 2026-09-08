<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SupportMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;

/**
 * One thing somebody said on a ticket.
 *
 * @property string $id
 * @property string $ticket_id
 * @property ?string $author_user_id
 * @property MessageAuthorKind $author_kind
 * @property string $body
 * @property bool $is_internal_note
 * @property CarbonImmutable $created_at
 */
class SupportMessage extends Model
{
    /** @use HasFactory<SupportMessageFactory> */
    use HasFactory;

    use HasUlids;

    public $incrementing = false;

    protected $table = 'support_messages';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'author_kind' => MessageAuthorKind::class,
            'is_internal_note' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @return HasMany<SupportAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'message_id');
    }
}
