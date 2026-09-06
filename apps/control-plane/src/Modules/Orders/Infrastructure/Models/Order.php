<?php

declare(strict_types=1);

namespace Lynomia\Modules\Orders\Infrastructure\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A customer's purchase.
 *
 * The status column is never assigned directly anywhere in the application:
 * every change goes through TransitionOrder, which validates it against the
 * state machine and records who caused it.
 *
 * @property string $id
 * @property OrderStatus $status
 * @property string $currency
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'billing_snapshot' => 'array',
            'placed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by_user_id');
    }

    /**
     * The coupon applied at checkout, if any.
     *
     * Nulled rather than cascaded when a coupon is deleted: the order is a
     * historical record and the discount is already on the invoice.
     *
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(OrderTransition::class);
    }

    public function subtotal(): Money
    {
        return Money::ofMinor($this->subtotal_minor, $this->currency);
    }

    public function discount(): Money
    {
        return Money::ofMinor($this->discount_minor, $this->currency);
    }

    public function tax(): Money
    {
        return Money::ofMinor($this->tax_minor, $this->currency);
    }

    public function total(): Money
    {
        return Money::ofMinor($this->total_minor, $this->currency);
    }
}
