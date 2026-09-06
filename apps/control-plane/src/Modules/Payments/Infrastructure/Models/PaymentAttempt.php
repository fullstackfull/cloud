<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure\Models;

use Database\Factories\PaymentAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;

/**
 * One try at collecting an invoice, successful or not.
 *
 * Kept separately from transactions because dunning reasons about attempts:
 * how many have been made, why the last one failed and when the next is due.
 * A transaction row only exists once a provider has something to say about the
 * money, whereas an attempt exists from the moment we decide to charge.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string|null $transaction_id
 * @property int $attempt_number
 * @property PaymentAttemptStatus $status
 */
class PaymentAttempt extends Model
{
    /** @use HasFactory<PaymentAttemptFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'attempt_number' => 'integer',
            'next_retry_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function isDue(): bool
    {
        return $this->status->isRetryable()
            && $this->next_retry_at !== null
            && $this->next_retry_at->isPast();
    }
}
