<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Concerns\RedactsJsonSecrets;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One movement of money between a customer and the platform.
 *
 * The (provider, provider_reference) unique index is the ledger's idempotency
 * key. Webhooks are redelivered, jobs are retried and operators press buttons
 * twice; the index is what makes all of those converge on one row instead of
 * three, and it is why RecordPaymentCapture can be safely called again with
 * the same event.
 *
 * @property string $id
 * @property string $customer_id
 * @property string|null $invoice_id
 * @property string $provider
 * @property string|null $provider_reference
 * @property TransactionKind $kind
 * @property TransactionStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property array<string, mixed>|null $provider_metadata
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasUlids, RedactsJsonSecrets;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TransactionKind::class,
            'status' => TransactionStatus::class,
            'amount_minor' => 'integer',
            'processed_at' => 'immutable_datetime',
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
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /**
     * @return HasMany<PaymentAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function amount(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    public function isCaptured(): bool
    {
        return $this->status === TransactionStatus::Succeeded
            && $this->kind === TransactionKind::Charge;
    }

    /**
     * Total of every refund that still lays claim to this capture.
     *
     * Pending refunds are included: the money is already promised, so counting
     * only settled ones would let a second refund be issued while the first is
     * still in flight.
     */
    public function refundedAmount(): Money
    {
        $minor = (int) $this->refunds()
            ->whereIn('status', Refund::fundReservingStatuses())
            ->sum('amount_minor');

        return Money::ofMinor($minor, $this->currency);
    }

    public function refundableAmount(): Money
    {
        return $this->amount()->minus($this->refundedAmount());
    }
}
