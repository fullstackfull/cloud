<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Infrastructure\Models;

use Database\Factories\CouponRedemptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * One use of a coupon by one customer.
 *
 * These rows are the audit trail behind coupons.redemption_count and the only
 * source for the per-customer limit. They are written inside the same
 * transaction that moves the counter, so the two can never disagree.
 *
 * @property string $id
 * @property string $coupon_id
 * @property string $customer_id
 * @property string|null $order_id
 * @property int $discount_amount_minor
 * @property string $currency
 */
class CouponRedemption extends Model
{
    /** @use HasFactory<CouponRedemptionFactory> */
    use HasFactory, HasUlids;

    /**
     * A redemption is a historical fact: the table carries no updated_at so a
     * row cannot look as though it was edited after the discount was given.
     */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function discount(): Money
    {
        return Money::ofMinor($this->discount_amount_minor, $this->currency);
    }
}
