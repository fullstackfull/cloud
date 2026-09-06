<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Exceptions;

use DateTimeInterface;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A campaign code used before its start date.
 *
 * Distinct from expiry because the remedy is different: this customer can come
 * back and succeed, an expired code will never work again.
 */
final class CouponNotYetValidException extends DomainException
{
    public static function forCoupon(string $couponId, string $code, DateTimeInterface $validFrom): self
    {
        $exception = new self(sprintf(
            'Coupon %s is not valid until %s.',
            $code,
            $validFrom->format(DateTimeInterface::ATOM),
        ));

        return $exception->withContext([
            'coupon_id' => $couponId,
            'code' => $code,
            'valid_from' => $validFrom->format(DateTimeInterface::ATOM),
        ]);
    }

    public function errorCode(): string
    {
        return 'coupon.not_yet_valid';
    }
}
