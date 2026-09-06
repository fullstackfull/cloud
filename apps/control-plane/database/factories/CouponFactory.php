<?php

declare(strict_types=1);

namespace Database\Factories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Catalog\Domain\Enums\DiscountType;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'SAVE'.Str::upper(Str::random(8)),
            'name' => ['en' => 'Launch discount', 'ar' => 'خصم الإطلاق'],
            'discount_type' => DiscountType::Percentage,
            // 10%, as an exact decimal string — never 0.1 as a float.
            'percentage' => '0.100000',
            'applies_to_renewals' => false,
            'max_redemptions_per_customer' => 1,
            'redemption_count' => 0,
            'is_active' => true,
        ];
    }

    public function code(string $code): static
    {
        return $this->state(fn (): array => ['code' => $code]);
    }

    public function percentage(string $rate): static
    {
        return $this->state(fn (): array => [
            'discount_type' => DiscountType::Percentage,
            'percentage' => $rate,
            'amount_minor' => null,
            'currency' => null,
        ]);
    }

    public function fixed(int $amountMinor, string $currency = 'KWD'): static
    {
        return $this->state(fn (): array => [
            'discount_type' => DiscountType::FixedAmount,
            'percentage' => null,
            'amount_minor' => $amountMinor,
            'currency' => strtoupper($currency),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function validBetween(?DateTimeInterface $from, ?DateTimeInterface $until): static
    {
        return $this->state(fn (): array => ['valid_from' => $from, 'valid_until' => $until]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'valid_from' => now()->subMonth(),
            'valid_until' => now()->subDay(),
        ]);
    }

    public function notYetValid(): static
    {
        return $this->state(fn (): array => ['valid_from' => now()->addDay()]);
    }

    public function limitedTo(int $maxRedemptions): static
    {
        return $this->state(fn (): array => ['max_redemptions' => $maxRedemptions]);
    }

    public function perCustomer(int $limit): static
    {
        return $this->state(fn (): array => ['max_redemptions_per_customer' => $limit]);
    }

    public function minimumOrder(int $amountMinor): static
    {
        return $this->state(fn (): array => ['minimum_order_amount_minor' => $amountMinor]);
    }

    /**
     * @param  list<string>  $planIds
     */
    public function forPlans(array $planIds): static
    {
        return $this->state(fn (): array => ['applicable_plan_ids' => $planIds]);
    }

    /**
     * @param  list<ProductKind>  $kinds
     */
    public function forProductKinds(array $kinds): static
    {
        return $this->state(fn (): array => [
            'applicable_product_kinds' => array_map(
                static fn (ProductKind $kind): string => $kind->value,
                $kinds,
            ),
        ]);
    }
}
