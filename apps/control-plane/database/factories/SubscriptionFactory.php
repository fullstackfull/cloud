<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = CarbonImmutable::now()->startOfSecond();
        $end = BillingPeriod::Monthly->advance($start);

        return [
            'customer_id' => Customer::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            // 9.000 KWD — three minor digits, as the currency requires.
            'recurring_amount_minor' => 9000,
            'current_period_start' => $start,
            'current_period_end' => $end,
            'next_invoice_at' => $end,
            'auto_renew' => true,
            'failed_payment_count' => 0,
        ];
    }

    /**
     * A subscription whose current period starts on a given date, with the
     * period end and the invoice date derived the way the platform derives
     * them — calendar-aware, never by adding 30 days.
     */
    public function startingOn(DateTimeInterface $start, BillingPeriod $period = BillingPeriod::Monthly): static
    {
        return $this->state(function () use ($start, $period): array {
            $from = CarbonImmutable::instance($start);
            $end = $period->advance($from);

            return [
                'billing_period' => $period,
                'current_period_start' => $from,
                'current_period_end' => $end,
                'next_invoice_at' => $end,
            ];
        });
    }

    public function status(SubscriptionStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function priced(int $recurringMinor, string $currency = 'KWD'): static
    {
        return $this->state(fn (): array => [
            'recurring_amount_minor' => $recurringMinor,
            'currency' => strtoupper($currency),
        ]);
    }

    public function pastDue(int $failedPayments = 1, ?DateTimeInterface $graceEndsAt = null): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::PastDue,
            'failed_payment_count' => $failedPayments,
            'grace_period_ends_at' => $graceEndsAt ?? CarbonImmutable::now()->addDays(7),
        ]);
    }

    public function suspended(?DateTimeInterface $suspendedAt = null): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Suspended,
            'suspended_at' => $suspendedAt ?? CarbonImmutable::now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => CarbonImmutable::now(),
            'ended_at' => CarbonImmutable::now(),
            'auto_renew' => false,
            'next_invoice_at' => null,
        ]);
    }

    public function withCoupon(string $couponId, ?int $cyclesRemaining): static
    {
        return $this->state(fn (): array => [
            'coupon_id' => $couponId,
            'coupon_cycles_remaining' => $cyclesRemaining,
        ]);
    }
}
