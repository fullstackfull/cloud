<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure\Models;

use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\Concerns\RedactsJsonSecrets;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Money returned to a customer against one captured transaction.
 *
 * A refund row is created before the provider is called, in the pending state,
 * and only then does the network request happen. That ordering is what bounds
 * the total: the reservation is visible to any concurrent refund the moment
 * the lock is released, whereas a row written after the provider replies
 * leaves a window in which two refunds each believe the full capture is
 * available.
 *
 * @property string $id
 * @property string $transaction_id
 * @property RefundStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property array<string, mixed>|null $provider_metadata
 */
class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, HasUlids, RedactsJsonSecrets;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'processed_at' => 'immutable_datetime',
            'recorded_on_invoice_at' => 'immutable_datetime',
        ];
    }

    /**
     * The statuses that still hold part of the capture, as raw column values
     * for use in queries.
     *
     * @return list<string>
     */
    public static function fundReservingStatuses(): array
    {
        return array_values(array_map(
            static fn (RefundStatus $status): string => $status->value,
            array_filter(
                RefundStatus::cases(),
                static fn (RefundStatus $status): bool => $status->reservesFunds(),
            ),
        ));
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }
}
