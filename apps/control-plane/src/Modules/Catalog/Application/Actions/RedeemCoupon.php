<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Lynomia\Modules\Catalog\Application\DTOs\CouponContext;
use Lynomia\Modules\Catalog\Domain\Exceptions\UnknownCouponException;
use Lynomia\Modules\Catalog\Domain\Services\CouponValidator;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\CouponRedemption;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * The only place a coupon is consumed.
 *
 * A coupon with one use left is a scarce resource, and the naive
 * "validate, then increment" is the textbook way to give it away twice: two
 * checkouts both read redemption_count = 0, both pass, both write. Nothing
 * about the validation being correct saves it — the two reads happened before
 * either write.
 *
 * So the counter is never trusted from the caller's copy of the row. Inside
 * one transaction the coupon is re-read with lockForUpdate(), the *whole*
 * validation is run again against that locked row, and only then are the
 * counter and the redemption written. A second checkout arriving mid-flight
 * blocks on the lock until the first commits, and then re-reads a
 * redemption_count that already includes it — so it is refused rather than
 * served. The per-customer limit is protected by the same lock, because the
 * count it reads is taken while that lock is held.
 *
 * The counter and the coupon_redemptions row move together or not at all,
 * which is what keeps redemption_count reconcilable against its audit trail.
 */
final readonly class RedeemCoupon
{
    public function __construct(
        private CouponValidator $validator,
    ) {}

    /**
     * @param  string|null  $orderId  the order the discount was granted on
     */
    public function execute(Coupon $coupon, CouponContext $context, ?string $orderId = null): CouponRedemption
    {
        /*
         * The transaction is opened on the coupon's own connection rather than
         * through the DB facade. A lock taken on a different connection than
         * the one that reads the row protects nothing, and a coupon loaded
         * from a second connection — which is exactly how the concurrency
         * tests reproduce two simultaneous checkouts — must lock and write
         * where it lives.
         */
        return $coupon->getConnection()->transaction(function () use ($coupon, $context, $orderId): CouponRedemption {
            /** @var Coupon $locked */
            $locked = $coupon->newQuery()->lockForUpdate()->findOrFail($coupon->getKey());

            // Re-validated in full, not just re-counted: a coupon can also
            // have been deactivated or have expired between the preview the
            // customer saw and this moment.
            $this->validator->validate($locked, $context);

            $discount = $this->validator->discountFor($locked, $context->orderAmount);

            $redemption = $this->write($locked, $context, $discount, $orderId);

            $locked->redemption_count = $locked->redemption_count + 1;
            $locked->save();

            // The caller's instance would otherwise keep answering with the
            // pre-redemption count and re-offer a coupon that is now spent.
            $coupon->redemption_count = $locked->redemption_count;
            $coupon->syncOriginalAttribute('redemption_count');

            return $redemption;
        });
    }

    /**
     * Resolve a pasted code and redeem it in one step.
     *
     * @throws UnknownCouponException
     */
    public function byCode(string $code, CouponContext $context, ?string $orderId = null): CouponRedemption
    {
        return $this->execute($this->validator->resolveCode($code), $context, $orderId);
    }

    private function write(Coupon $coupon, CouponContext $context, Money $discount, ?string $orderId): CouponRedemption
    {
        /** @var CouponRedemption $redemption */
        $redemption = $coupon->redemptions()->create([
            'customer_id' => $context->customer->getKey(),
            'order_id' => $orderId,
            // The discount is stamped rather than recomputed later: a campaign
            // whose percentage is edited afterwards must not restate what a
            // customer was actually given.
            'discount_amount_minor' => $discount->minorUnits(),
            'currency' => $discount->currency(),
        ]);

        return $redemption;
    }
}
