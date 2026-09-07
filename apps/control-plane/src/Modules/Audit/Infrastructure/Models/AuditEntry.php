<?php

declare(strict_types=1);

namespace Lynomia\Modules\Audit\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AuditEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Shared\Infrastructure\Casts\RedactedJsonCast;
use RuntimeException;

/**
 * One irreversible act, recorded permanently.
 *
 * Append-only, and enforced rather than documented: the model refuses to be
 * updated or deleted at all. A trail that can be amended by the same
 * application that writes it answers a different question from the one an
 * auditor is asking, and the enforcement has to live somewhere a careless
 * `AuditEntry::query()->update(...)` will hit.
 *
 * @property string $id
 * @property ?string $actor_id
 * @property string $actor_type
 * @property ?string $actor_label
 * @property AuditAction $action
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property ?string $customer_id
 * @property ?array<string, mixed> $context
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property CarbonImmutable $created_at
 */
class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory, HasUlids;

    protected $table = 'audit_log';

    // There is no updated_at column, and there must never be one.
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            // The same cast the drift and provisioning tables use. Audit rows
            // quote request payloads and payloads carry tokens, and this table
            // is read by more people than any other.
            'context' => RedactedJsonCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('An audit entry cannot be modified once it has been written.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('An audit entry cannot be deleted.');
        });
    }
}
