<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * This customer has used the coupon as often as they are allowed to.
 *
 * The per-customer limit is independent of the global one: a campaign with ten
 * thousand uses left still refuses an eleventh order from the same customer on
 * a one-per-customer coupon.
 */
final class CouponCustomerLimitReachedException extends DomainException
{
    public static function forCustomer(string $couponId, string $code, string $customerId, int $redeemed, int $limit): self
    {
        $exception = new self(sprintf(
            'Customer %s has already redeemed coupon %s %d of %d permitted times.',
            $customerId,
            $code,
            $redeemed,
            $limit,
        ));

        return $exception->withContext([
            'coupon_id' => $couponId,
            'code' => $code,
            'customer_id' => $customerId,
            'redemption_count' => $redeemed,
            'max_redemptions_per_customer' => $limit,
        ]);
    }

    public function errorCode(): string
    {
        return 'coupon.customer_limit_reached';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
