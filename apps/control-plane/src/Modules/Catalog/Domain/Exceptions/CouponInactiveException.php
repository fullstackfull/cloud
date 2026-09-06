<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The coupon exists but has been switched off.
 *
 * Kept distinct from an unknown code so support can tell a customer holding a
 * withdrawn campaign code apart from one who mistyped.
 */
final class CouponInactiveException extends DomainException
{
    public static function forCoupon(string $couponId, string $code): self
    {
        $exception = new self(sprintf('Coupon %s is not active.', $code));

        return $exception->withContext(['coupon_id' => $couponId, 'code' => $code]);
    }

    public function errorCode(): string
    {
        return 'coupon.inactive';
    }
}
