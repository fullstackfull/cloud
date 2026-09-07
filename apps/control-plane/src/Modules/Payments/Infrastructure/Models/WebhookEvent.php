<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure\Models;

use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Lynomia\Modules\Payments\Domain\Enums\WebhookEventStatus;
use Lynomia\Modules\Shared\Infrastructure\Casts\RedactedJsonCast;

/**
 * The record of one inbound provider event.
 *
 * This table is the replay defence, not an audit convenience. The unique
 * (provider, provider_event_id) index is written *before* the event is acted
 * on, so a redelivery — including two workers receiving the same event in the
 * same instant — finds an existing row and returns its recorded outcome
 * instead of capturing a second time. Acting first and recording afterwards
 * would leave exactly the window in which a duplicate charge appears.
 *
 * Only verified payloads ever reach this table. signature_verified_by records
 * which scheme accepted it, which is the question an operator asks first when
 * a signing secret is rotated.
 *
 * @property string $id
 * @property string $provider
 * @property string $provider_event_id
 * @property string $event_type
 * @property WebhookEventStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property array<string, mixed>|null $payload
 */
class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => RedactedJsonCast::class,
            'provider_metadata' => RedactedJsonCast::class,
            'status' => WebhookEventStatus::class,
            'attempts' => 'integer',
            'provider_created_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }
}
