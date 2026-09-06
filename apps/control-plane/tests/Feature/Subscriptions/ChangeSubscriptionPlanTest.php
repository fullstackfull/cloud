<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Subscriptions\Application\Actions\ChangeSubscriptionPlan;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\IncompatibleBillingPeriodException;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\SubscriptionNotChangeableException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ChangeSubscriptionPlanTest extends TestCase
{
    use RefreshDatabase;

    private ChangeSubscriptionPlan $change;

    protected function setUp(): void
    {
        parent::setUp();

        $this->change = app(ChangeSubscriptionPlan::class);
    }

    #[Test]
    public function an_upgrade_credits_the_unused_remainder_and_charges_the_same_remainder_of_the_new_plan(): void
    {
        [$small] = $this->plan('CX-2', 9000);
        [$large, $largePrice] = $this->plan('CX-4', 18000);

        $subscription = $this->subscriptionOn($small, 9000, '2026-02-01 00:00:00');

        // Exactly half of a 28-day February period.
        $this->travelTo(CarbonImmutable::parse('2026-02-15 00:00:00'));

        $proration = $this->change->execute($subscription, $large, $largePrice);

        $this->assertSame('-4.500', $proration->credit->toDecimalString());
        $this->assertSame('9.000', $proration->charge->toDecimalString());
        $this->assertSame('4.500', $proration->net()->toDecimalString());
        $this->assertTrue($proration->isUpgrade());

        $this->assertSame(InvoiceItemKind::Credit, $proration->lines[0]->kind);
        $this->assertSame('Unused time on CX-2', $proration->lines[0]->line->description);
        $this->assertSame(InvoiceItemKind::Proration, $proration->lines[1]->kind);
        $this->assertSame('Remainder of period on CX-4', $proration->lines[1]->line->description);

        // A coupon must not reduce a credit: that would hand back less than
        // the customer paid for the time they did not use.
        $this->assertFalse($proration->lines[0]->line->discountable);
        $this->assertFalse($proration->lines[1]->line->discountable);

        $subscription->refresh();
        $this->assertSame($large->id, trim((string) $subscription->plan_id));
        $this->assertSame(18000, $subscription->recurring_amount_minor);
        // A plan change is not a renewal: the anniversary does not move.
        $this->assertSame('2026-03-01 00:00:00', $subscription->current_period_end->toDateTimeString());
        $this->assertSame('2026-03-01 00:00:00', $subscription->next_invoice_at->toDateTimeString());
    }

    #[Test]
    public function an_upgrade_followed_by_an_immediate_downgrade_nets_to_zero(): void
    {
        // Deliberately awkward figures and an awkward instant: if the credit
        // and the charge were computed from different divisors, or rounded
        // independently, the round trip would leave a residue behind.
        [$small, $smallPrice] = $this->plan('CX-2', 9333);
        [$large, $largePrice] = $this->plan('CX-8', 21777);

        $subscription = $this->subscriptionOn($small, 9333, '2026-02-01 00:00:00');

        $this->travelTo(CarbonImmutable::parse('2026-02-14 07:13:11'));

        $up = $this->change->execute($subscription, $large, $largePrice);
        $down = $this->change->execute($subscription->refresh(), $small, $smallPrice);

        $this->assertTrue($up->net()->plus($down->net())->isZero());
        $this->assertTrue($up->charge->plus($down->credit)->isZero());
        $this->assertTrue($up->credit->plus($down->charge)->isZero());

        $subscription->refresh();
        $this->assertSame($small->id, trim((string) $subscription->plan_id));
        $this->assertSame(9333, $subscription->recurring_amount_minor);
    }

    #[Test]
    public function a_change_at_the_very_end_of_a_period_credits_and_charges_nothing(): void
    {
        [$small] = $this->plan('CX-2', 9000);
        [$large, $largePrice] = $this->plan('CX-4', 18000);

        $subscription = $this->subscriptionOn($small, 9000, '2026-02-01 00:00:00');

        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));

        $proration = $this->change->execute($subscription, $large, $largePrice);

        // There is no unused time left to credit, and none to charge for.
        $this->assertTrue($proration->credit->isZero());
        $this->assertTrue($proration->charge->isZero());
        $this->assertTrue($proration->net()->isZero());
    }

    #[Test]
    public function a_plan_priced_in_another_currency_cannot_be_switched_to(): void
    {
        [$small] = $this->plan('CX-2', 9000);
        [$large, $largePrice] = $this->plan('CX-4', 1800, currency: 'USD');

        $subscription = $this->subscriptionOn($small, 9000, '2026-02-01 00:00:00');

        $this->travelTo(CarbonImmutable::parse('2026-02-15 00:00:00'));

        $this->expectException(CurrencyMismatchException::class);

        $this->change->execute($subscription, $large, $largePrice);
    }

    #[Test]
    public function a_plan_on_another_billing_period_cannot_be_prorated_onto(): void
    {
        [$small] = $this->plan('CX-2', 9000);
        [$yearly, $yearlyPrice] = $this->plan('CX-4', 90000, period: BillingPeriod::Yearly);

        $subscription = $this->subscriptionOn($small, 9000, '2026-02-01 00:00:00');

        $this->travelTo(CarbonImmutable::parse('2026-02-15 00:00:00'));

        $this->expectException(IncompatibleBillingPeriodException::class);

        $this->change->execute($subscription, $yearly, $yearlyPrice);
    }

    #[Test]
    public function a_suspended_subscription_cannot_change_plan(): void
    {
        [$small] = $this->plan('CX-2', 9000);
        [$large, $largePrice] = $this->plan('CX-4', 18000);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-02-01 00:00:00'))
            ->priced(9000)
            ->suspended()
            ->create(['plan_id' => $small->id]);

        $this->expectException(SubscriptionNotChangeableException::class);

        $this->change->execute($subscription, $large, $largePrice);
    }

    /**
     * @return array{0: Plan, 1: PlanPrice}
     */
    private function plan(
        string $name,
        int $recurringMinor,
        string $currency = 'KWD',
        BillingPeriod $period = BillingPeriod::Monthly,
    ): array {
        $plan = Plan::factory()->create(['name' => ['en' => $name]]);

        $price = PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => $currency,
            'billing_period' => $period,
            'recurring_amount_minor' => $recurringMinor,
        ]);

        return [$plan, $price];
    }

    private function subscriptionOn(Plan $plan, int $recurringMinor, string $periodStart): Subscription
    {
        return Subscription::factory()
            ->startingOn(CarbonImmutable::parse($periodStart))
            ->priced($recurringMinor)
            ->create(['plan_id' => $plan->id]);
    }
}
