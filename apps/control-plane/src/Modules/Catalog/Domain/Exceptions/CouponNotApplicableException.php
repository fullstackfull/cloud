<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The basket contains something the campaign does not cover.
 *
 * A restricted coupon is refused outright rather than applied to the covered
 * part of the order: the discount is computed over the order as a whole, so a
 * partial match would quietly discount lines the campaign never funded.
 */
final class CouponNotApplicableException extends DomainException
{
    /**
     * @param  list<string>  $offendingPlanIds
     */
    public static function forPlans(string $couponId, string $code, array $offendingPlanIds): self
    {
        $exception = new self(sprintf(
            'Coupon %s does not apply to every plan on this order.',
            $code,
        ));

        return $exception->withContext([
            'coupon_id' => $couponId,
            'code' => $code,
            'restriction' => 'plan',
            'offending_plan_ids' => implode(',', $offendingPlanIds),
        ]);
    }

    /**
     * @param  list<string>  $offendingKinds  product kind values, as the basket carried them
     */
    public static function forProductKinds(string $couponId, string $code, array $offendingKinds): self
    {
        $exception = new self(sprintf(
            'Coupon %s does not apply to every product kind on this order.',
            $code,
        ));

        return $exception->withContext([
            'coupon_id' => $couponId,
            'code' => $code,
            'restriction' => 'product_kind',
            'offending_product_kinds' => implode(',', $offendingKinds),
        ]);
    }

    public function errorCode(): string
    {
        return 'coupon.not_applicable';
    }
}
