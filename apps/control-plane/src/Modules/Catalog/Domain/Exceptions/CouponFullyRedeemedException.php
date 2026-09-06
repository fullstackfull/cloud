<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The campaign has handed out every use it was funded for.
 *
 * Raised from inside the redemption transaction, after the coupon row has been
 * locked, so the count it reports is the committed one rather than whatever a
 * concurrent checkout had read a moment earlier.
 */
final class CouponFullyRedeemedException extends DomainException
{
    public static function forCoupon(string $couponId, string $code, int $redeemed, int $limit): self
    {
        $exception = new self(sprintf(
            'Coupon %s has been redeemed %d of %d times and is exhausted.',
            $code,
            $redeemed,
            $limit,
        ));

        return $exception->withContext([
            'coupon_id' => $couponId,
            'code' => $code,
            'redemption_count' => $redeemed,
            'max_redemptions' => $limit,
        ]);
    }

    public function errorCode(): string
    {
        return 'coupon.fully_redeemed';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
