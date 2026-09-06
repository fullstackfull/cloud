<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A coupon denominated in one currency was offered against an order in another.
 *
 * This is deliberately a domain refusal rather than the generic money-layer
 * CurrencyMismatchException: a customer pasting a KWD launch code into a USD
 * checkout is ordinary user input that the API should answer with a 422, not a
 * programming error worth a 500.
 */
final class CouponCurrencyMismatchException extends DomainException
{
    public static function between(string $couponId, string $code, string $couponCurrency, string $orderCurrency): self
    {
        $exception = new self(sprintf(
            'Coupon %s is denominated in %s and cannot be applied to a %s order.',
            $code,
            $couponCurrency,
            $orderCurrency,
        ));

        return $exception->withContext([
            'coupon_id' => $couponId,
            'code' => $code,
            'coupon_currency' => $couponCurrency,
            'order_currency' => $orderCurrency,
        ]);
    }

    public function errorCode(): string
    {
        return 'coupon.currency_mismatch';
    }
}
