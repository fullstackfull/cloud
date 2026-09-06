<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Exceptions;

use DateTimeInterface;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

final class CouponExpiredException extends DomainException
{
    public static function forCoupon(string $couponId, string $code, DateTimeInterface $validUntil): self
    {
        $exception = new self(sprintf(
            'Coupon %s expired on %s.',
            $code,
            $validUntil->format(DateTimeInterface::ATOM),
        ));

        return $exception->withContext([
            'coupon_id' => $couponId,
            'code' => $code,
            'valid_until' => $validUntil->format(DateTimeInterface::ATOM),
        ]);
    }

    public function errorCode(): string
    {
        return 'coupon.expired';
    }
}
