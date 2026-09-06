<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Catalog\Infrastructure\Models\CouponRedemption;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;

/**
 * @extends Factory<CouponRedemption>
 */
class CouponRedemptionFactory extends Factory
{
    protected $model = CouponRedemption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'coupon_id' => Coupon::factory(),
            'customer_id' => Customer::factory(),
            'discount_amount_minor' => 900,
            'currency' => 'KWD',
            'created_at' => now(),
        ];
    }
}
