<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Enums;

enum DiscountType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';

    /**
     * Whether the coupon is denominated in a currency of its own.
     *
     * A fixed-amount coupon is 5.000 KWD and nothing else — it cannot be
     * spent against a USD order, because the platform never converts. A
     * percentage is dimensionless and applies to any currency.
     */
    public function isCurrencyBound(): bool
    {
        return $this === self::FixedAmount;
    }
}
