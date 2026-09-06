<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Infrastructure\Models;

use Brick\Math\BigDecimal;
use Database\Factories\CouponFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Lynomia\Modules\Catalog\Domain\Enums\DiscountType;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A discount code.
 *
 * `redemption_count` is a counter, not a derived figure, and it is only ever
 * moved by RedeemCoupon while holding a row lock on this record. Nothing else
 * may write it: a coupon with one use left is a scarce resource, and counting
 * coupon_redemptions at validation time without the lock is exactly the read
 * that two simultaneous checkouts both pass.
 *
 * @property string $id
 * @property string $code
 * @property DiscountType $discount_type
 * @property string|null $percentage exact decimal string such as "0.100000"
 * @property int|null $amount_minor
 * @property string|null $currency
 * @property int $redemption_count
 * @property int|null $max_redemptions
 * @property int $max_redemptions_per_customer
 * @property int|null $minimum_order_amount_minor
 * @property list<string>|null $applicable_plan_ids
 * @property list<string>|null $applicable_product_kinds
 * @property bool $is_active
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'discount_type' => DiscountType::class,
            // `percentage` is deliberately absent: Laravel's decimal cast
            // formats through a float, and 0.150000 is not representable as
            // one. The raw numeric arrives from PostgreSQL as an exact string
            // and is handed to BigDecimal untouched.
            'amount_minor' => 'integer',
            'applies_to_renewals' => 'boolean',
            'duration_cycles' => 'integer',
            'max_redemptions' => 'integer',
            'max_redemptions_per_customer' => 'integer',
            'redemption_count' => 'integer',
            'minimum_order_amount_minor' => 'integer',
            'applicable_plan_ids' => 'array',
            'applicable_product_kinds' => 'array',
            'is_active' => 'boolean',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /**
     * Reduces a code to the form it is compared and stored in.
     *
     * Customers do not type codes, they paste them — out of an email, a chat
     * message or a banner — and what arrives carries trailing newlines,
     * non-breaking spaces and the occasional zero-width character. Stripping
     * every separator and format character and upper-casing is what makes
     * " launch-25 " and "LAUNCH-25" the same coupon.
     */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper((string) preg_replace('/[\p{Z}\p{C}]+/u', '', $code));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCode(Builder $query, string $code): Builder
    {
        // upper() on the column rather than a plain equality, so a row written
        // in mixed case by a fixture or an import still matches.
        return $query->whereRaw('upper(code) = ?', [self::normalizeCode($code)]);
    }

    public function isPercentage(): bool
    {
        return $this->discount_type === DiscountType::Percentage;
    }

    /**
     * The percentage as an exact decimal string, e.g. "0.100000" for 10%.
     */
    public function percentageRate(): string
    {
        return (string) BigDecimal::of($this->percentage ?? '0');
    }

    /**
     * The fixed discount, or null for a percentage coupon.
     */
    public function fixedAmount(): ?Money
    {
        if ($this->amount_minor === null || $this->currency === null) {
            return null;
        }

        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /**
     * The order value below which the coupon is refused.
     *
     * A percentage coupon carries no currency of its own, so the threshold is
     * read in the currency of the order being priced. A currency-bound coupon
     * has already been checked against that currency before this is called.
     */
    public function minimumOrderAmount(string $orderCurrency): ?Money
    {
        if ($this->minimum_order_amount_minor === null) {
            return null;
        }

        return Money::ofMinor($this->minimum_order_amount_minor, $this->currency ?? $orderCurrency);
    }

    /**
     * @return list<ProductKind>
     */
    public function applicableProductKinds(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $kind): ?ProductKind => is_string($kind) ? ProductKind::tryFrom($kind) : null,
            $this->applicable_product_kinds ?? [],
        )));
    }

    /**
     * @return list<string>
     */
    public function applicablePlanIds(): array
    {
        return array_values(array_filter(
            $this->applicable_plan_ids ?? [],
            static fn (mixed $id): bool => is_string($id) && $id !== '',
        ));
    }

    public function hasGlobalCapacity(): bool
    {
        return $this->max_redemptions === null || $this->redemption_count < $this->max_redemptions;
    }

    public function isWithinValidityWindow(DateTimeInterface $at): bool
    {
        return ($this->valid_from === null || $this->valid_from->lessThanOrEqualTo($at))
            && ($this->valid_until === null || $this->valid_until->greaterThan($at));
    }
}
