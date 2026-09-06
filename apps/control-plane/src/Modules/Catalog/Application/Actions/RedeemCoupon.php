<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Carbon\CarbonImmutable;
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
 *
 * The same lock is what makes a repeat delivery cheap to recognise: a call
 * naming an order that has already redeemed this coupon returns the redemption
 * it was given the first time instead of writing a second one.
 */
final readonly class RedeemCoupon
{
    public function __construct(
        private CouponValidator $validator,
    ) {}

    /**
     * @param  string|null  $orderId  the order the discount was granted on; supplying it
     *                                is what makes a repeated call idempotent
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

            $already = $this->existingRedemption($locked, $orderId);

            if ($already !== null) {
                $this->refresh($coupon, $locked);

                return $already;
            }

            /*
             * Re-validated in full, not just re-counted — and re-validated
             * against *now*, not against the instant the preview was taken.
             * The context carries the moment the customer was quoted, which
             * may be minutes or days old by the time the order is paid; a
             * campaign that has ended in between is over, and re-running the
             * window check against the stale instant would hand out a discount
             * the campaign is no longer funding.
             */
            $this->validator->validate($locked, $context->judgedAt(CarbonImmutable::now()));

            $discount = $this->validator->discountFor($locked, $context->orderAmount);

            $redemption = $this->write($locked, $context, $discount, $orderId);

            $locked->redemption_count = $locked->redemption_count + 1;
            $locked->save();

            $this->refresh($coupon, $locked);

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

    /**
     * The redemption this order has already been given, if it has been here before.
     *
     * Redemption is driven by events that are redelivered as a matter of
     * course: a payment webhook arrives twice, a queued job is retried after a
     * timeout that the first attempt survived. Without this, the second
     * delivery writes a second audit row and moves redemption_count again, and
     * the campaign quietly pays for one order twice.
     *
     * The read is safe as a plain select precisely because it happens after the
     * coupon row has been locked: every redemption of this coupon is
     * serialised behind that lock, so nothing can insert a competing row
     * between this check and the write that follows it. An order id is the only
     * handle a caller has for "this same redemption again" — a redemption with
     * no order attached cannot be recognised on a second delivery and is
     * documented on execute() as not being idempotent.
     */
    private function existingRedemption(Coupon $coupon, ?string $orderId): ?CouponRedemption
    {
        if ($orderId === null || $orderId === '') {
            return null;
        }

        /** @var CouponRedemption|null $redemption */
        $redemption = $coupon->redemptions()->where('order_id', $orderId)->first();

        return $redemption;
    }

    /**
     * Carries the committed counter back onto the caller's own instance, which
     * would otherwise keep answering with the pre-redemption count and re-offer
     * a coupon that is now spent.
     */
    private function refresh(Coupon $caller, Coupon $locked): void
    {
        $caller->redemption_count = $locked->redemption_count;
        $caller->syncOriginalAttribute('redemption_count');
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
