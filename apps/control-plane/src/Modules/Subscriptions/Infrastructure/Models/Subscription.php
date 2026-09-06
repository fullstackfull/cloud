<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Infrastructure\Models;

use Database\Factories\SubscriptionFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A recurring commitment: one plan, one price, one billing period.
 *
 * The row carries three clocks that are deliberately kept apart, because
 * conflating them is what makes billing systems double-charge:
 *
 *  - current_period_start/end — the window the customer has paid for;
 *  - next_invoice_at — when the renewal worker should bill, which may lead the
 *    period end so an invoice reaches the customer before service continues;
 *  - grace_period_ends_at / suspended_at — the dunning clocks, which run only
 *    after a payment has actually failed.
 *
 * The status column is never assigned outside TransitionSubscription, which
 * validates every change against the state machine.
 *
 * @property string $id
 * @property SubscriptionStatus $status
 * @property BillingPeriod $billing_period
 * @property string $currency
 * @property int $recurring_amount_minor
 * @property int $failed_payment_count
 * @property ?int $coupon_cycles_remaining
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_period' => BillingPeriod::class,
            'recurring_amount_minor' => 'integer',
            'failed_payment_count' => 'integer',
            'coupon_cycles_remaining' => 'integer',
            'auto_renew' => 'boolean',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'next_invoice_at' => 'immutable_datetime',
            'cancel_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'grace_period_ends_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * What one period of this subscription costs.
     */
    public function recurringAmount(): Money
    {
        return Money::ofMinor($this->recurring_amount_minor, $this->currency);
    }

    /**
     * Whether the underlying service should still be answering requests.
     *
     * Past due deliberately still runs — see SubscriptionStatus.
     */
    public function serviceIsRunning(): bool
    {
        return $this->status->serviceShouldRun();
    }

    /**
     * Whether a scheduled cancellation lands on or before the end of the
     * window the customer has already paid for, which is what "cancel at the
     * end of the period" means once the worker looks at it.
     */
    public function isScheduledToCancel(): bool
    {
        return $this->cancel_at !== null
            && $this->cancel_at->lessThanOrEqualTo($this->current_period_end);
    }

    public function isDueForRenewal(?DateTimeInterface $at = null): bool
    {
        $due = $this->next_invoice_at ?? $this->current_period_end;

        return $this->status->shouldRenew()
            && $this->auto_renew
            && ! $this->isScheduledToCancel()
            && $due->lessThanOrEqualTo($at ?? now());
    }

    /**
     * Everything the renewal worker should bill, cheaply — this is what the
     * (status, next_invoice_at) index exists for.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDueForRenewal(Builder $query, ?DateTimeInterface $at = null): Builder
    {
        return $query
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->where('auto_renew', true)
            ->whereNotNull('next_invoice_at')
            ->where('next_invoice_at', '<=', $at ?? now())
            ->where(fn (Builder $inner): Builder => $inner
                ->whereNull('cancel_at')
                ->orWhereColumn('cancel_at', '>', 'current_period_end'));
    }
}
