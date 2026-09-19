<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Identity\Domain\Enums\LegalDocumentType;
use RuntimeException;

/**
 * One person's acceptance of one revision of one document.
 *
 * Append-only, and enforced here rather than trusted: an acceptance is
 * evidence about a moment that has already happened, and evidence that can be
 * edited afterwards is not evidence. Accepting a newer revision writes a new
 * row; the old one is how the platform can still say what was agreed last
 * year.
 *
 * @property string $id
 * @property string $user_id
 * @property LegalDocumentType $document_type
 * @property string $document_version
 * @property string $document_url
 * @property CarbonImmutable $accepted_at
 */
class LegalAcceptance extends Model
{
    use HasUlids;

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $table = 'legal_acceptances';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => LegalDocumentType::class,
            'accepted_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        /*
         * Not a database trigger and not only a convention.
         *
         * A revoked grant, a corrected version string or a tidy-up that
         * rewrote one of these rows would silently change what the platform
         * claims a customer agreed to. There is no legitimate caller for
         * either operation, so both are refused loudly where any caller will
         * meet them.
         */
        static::updating(static function (): never {
            throw new RuntimeException(
                'A legal acceptance records something that already happened and cannot be updated. '
                .'Record a new acceptance instead.',
            );
        });

        static::deleting(static function (): never {
            throw new RuntimeException(
                'A legal acceptance cannot be deleted. It is removed only with the account it belongs to, '
                .'by the foreign key.',
            );
        });
    }
}
