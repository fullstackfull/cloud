<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Infrastructure\Repositories;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Domain\ValueObjects\CouponTerms;

/**
 * Reads coupon terms for a renewal.
 *
 * Deliberately a read-only projection over the coupons table rather than an
 * Eloquent model: coupon lifecycle — validity windows, redemption limits,
 * counters — belongs to the catalogue and checkout side of the platform, and a
 * renewal has no business being able to write any of it. What it needs is a
 * value it can price with.
 */
final class CouponTermsRepository
{
    public function find(?string $couponId): ?CouponTerms
    {
        if ($couponId === null) {
            return null;
        }

        $row = DB::table('coupons')
            ->select(['id', 'code', 'applies_to_renewals', 'percentage', 'amount_minor', 'currency', 'duration_cycles'])
            ->where('id', $couponId)
            ->where('is_active', true)
            ->first();

        if ($row === null) {
            return null;
        }

        return new CouponTerms(
            id: (string) $row->id,
            code: (string) $row->code,
            appliesToRenewals: (bool) $row->applies_to_renewals,
            // numeric(9,6) arrives as an exact decimal string; keeping it a
            // string is what stops a rate ever becoming a float.
            percentage: $row->percentage === null ? null : (string) $row->percentage,
            fixedAmount: $row->amount_minor === null || $row->currency === null
                ? null
                : Money::ofMinor((int) $row->amount_minor, (string) $row->currency),
            durationCycles: $row->duration_cycles === null ? null : (int) $row->duration_cycles,
        );
    }
}
