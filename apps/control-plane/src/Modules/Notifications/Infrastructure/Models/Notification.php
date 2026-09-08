<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationCategory;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Shared\Infrastructure\Casts\RedactedJsonCast;

/**
 * One thing the platform told a customer.
 *
 * The row holds the facts, never the prose. `data` carries the hostname, the
 * amount, the invoice number; the sentence is built at read time from the
 * customer's current language. A customer who switches to Arabic must see
 * their history in Arabic, and a stored English string cannot be translated
 * afterwards.
 *
 * @property string $id
 * @property string $customer_id
 * @property ?string $user_id
 * @property NotificationType $type
 * @property NotificationCategory $category
 * @property ?string $subject_type
 * @property ?string $subject_id
 * @property ?array<string, mixed> $data
 * @property ?string $link
 * @property ?CarbonImmutable $read_at
 * @property string $idempotency_key
 * @property CarbonImmutable $created_at
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'category' => NotificationCategory::class,
            // The same redacting cast the audit and drift tables use. Payloads
            // reach this column from provider errors, and a bounce message can
            // quote a credential.
            'data' => RedactedJsonCast::class,
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<NotificationDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
