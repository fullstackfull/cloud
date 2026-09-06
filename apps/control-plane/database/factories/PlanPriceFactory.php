<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;

/**
 * @extends Factory<PlanPrice>
 */
class PlanPriceFactory extends Factory
{
    protected $model = PlanPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            // 9.000 KWD — three minor digits, as the currency requires.
            'recurring_amount_minor' => 9000,
            'setup_amount_minor' => 0,
            'is_active' => true,
        ];
    }

    public function currency(string $currency, int $recurringMinor): static
    {
        return $this->state(fn (): array => [
            'currency' => strtoupper($currency),
            'recurring_amount_minor' => $recurringMinor,
        ]);
    }

    public function period(BillingPeriod $period): static
    {
        return $this->state(fn (): array => ['billing_period' => $period]);
    }

    public function withSetupFee(int $setupMinor): static
    {
        return $this->state(fn (): array => ['setup_amount_minor' => $setupMinor]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['available_until' => now()->subDay()]);
    }
}
