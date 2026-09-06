<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

final class OrderBelowCouponMinimumException extends DomainException
{
    public static function forOrder(string $couponId, string $code, Money $orderAmount, Money $minimum): self
    {
        $exception = new self(sprintf(
            'Coupon %s requires an order of at least %s; this order is %s.',
            $code,
            (string) $minimum,
            (string) $orderAmount,
        ));

        return $exception->withContext([
            'coupon_id' => $couponId,
            'code' => $code,
            'order_amount_minor' => $orderAmount->minorUnits(),
            'minimum_order_amount_minor' => $minimum->minorUnits(),
            'currency' => $minimum->currency(),
        ]);
    }

    public function errorCode(): string
    {
        return 'coupon.order_below_minimum';
    }
}
