<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Notifications\Domain\Enums\DeliveryStatus;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;

/**
 * One attempt to get one notification down one channel.
 *
 * Separate from the notification itself so that a failed email does not make
 * the in-app copy look failed, and so that "why did this customer not get the
 * mail" has an answer with a reason in it.
 *
 * @property string $id
 * @property string $notification_id
 * @property NotificationChannel $channel
 * @property DeliveryStatus $status
 * @property ?string $destination
 * @property int $attempts
 * @property ?CarbonImmutable $sent_at
 * @property ?CarbonImmutable $failed_at
 * @property ?string $failure_reason
 */
class NotificationDelivery extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => DeliveryStatus::class,
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
