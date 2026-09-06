<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoice;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceLineDraft;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Services\PricingEngine;
use Lynomia\Modules\Billing\Domain\ValueObjects\TaxRate;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Subscriptions\Application\Actions\CancelSubscription;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewSubscription;
use Lynomia\Modules\Subscriptions\Domain\Exceptions\SubscriptionNotRenewableException;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RenewSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private RenewSubscription $renew;

    protected function setUp(): void
    {
        parent::setUp();

        $this->renew = app(RenewSubscription::class);
    }

    #[Test]
    public function a_31_january_monthly_subscription_renews_on_28_february_not_3_march(): void
    {
        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2025-12-31 08:00:00'))
            ->create();

        $this->assertSame('2026-01-31 08:00:00', $subscription->current_period_end->toDateTimeString());

        $this->travelTo(CarbonImmutable::parse('2026-01-31 08:00:00'));

        $plan = $this->renew->execute($subscription);

        $this->assertNotNull($plan);
        $this->assertSame('2026-01-31 08:00:00', $plan->periodStart->toDateTimeString());
        $this->assertSame('2026-02-28 08:00:00', $plan->periodEnd->toDateTimeString());

        $subscription->refresh();
        // The new period starts where the old one ended — no gap the customer
        // paid for, and no overlap billed twice.
        $this->assertSame('2026-01-31 08:00:00', $subscription->current_period_start->toDateTimeString());
        $this->assertSame('2026-02-28 08:00:00', $subscription->current_period_end->toDateTimeString());
        $this->assertSame('2026-02-28 08:00:00', $subscription->next_invoice_at->toDateTimeString());
    }

    #[Test]
    public function running_the_renewal_worker_twice_in_the_same_minute_advances_one_period(): void
    {
        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))
            ->create();

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:05'));

        $first = $this->renew->execute($subscription);

        // The second worker is holding the copy it read before the first one
        // committed — the case a plain "is it due?" check cannot see.
        $second = $this->renew->execute($subscription);

        $subscription->refresh();
        $third = $this->renew->execute($subscription);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertNull($third);
        $this->assertSame('2026-03-01 00:00:00', $subscription->current_period_end->toDateTimeString());
        $this->assertSame('2026-02-01 00:00:00', $subscription->current_period_start->toDateTimeString());
    }

    #[Test]
    public function a_subscription_that_is_not_yet_due_is_not_renewed(): void
    {
        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))
            ->create();

        $this->travelTo(CarbonImmutable::parse('2026-01-20 00:00:00'));

        $this->assertNull($this->renew->execute($subscription));
        $this->assertSame(
            '2026-02-01 00:00:00',
            $subscription->refresh()->current_period_end->toDateTimeString(),
        );
    }

    #[Test]
    public function a_cancelled_subscription_is_not_renewed_by_the_worker(): void
    {
        $cancelled = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))
            ->cancelled()
            // Deliberately still carrying a due invoice date, so the test
            // proves the status is what stops the renewal rather than a
            // conveniently nulled column.
            ->create(['next_invoice_at' => CarbonImmutable::parse('2026-02-01 00:00:00')]);

        $active = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))
            ->create();

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $due = Subscription::query()->dueForRenewal()->pluck('id')->all();

        $this->assertContains($active->id, $due);
        $this->assertNotContains($cancelled->id, $due);

        $this->expectException(SubscriptionNotRenewableException::class);

        $this->renew->execute($cancelled);
    }

    #[Test]
    public function a_subscription_scheduled_to_cancel_is_not_renewed(): void
    {
        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))
            ->create();

        app(CancelSubscription::class)->execute($subscription);
        $subscription->refresh();

        // Cancelling at the end of the period leaves the service running: the
        // customer paid for January and keeps it.
        $this->assertTrue($subscription->serviceIsRunning());
        $this->assertSame('2026-02-01 00:00:00', $subscription->cancel_at->toDateTimeString());

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $this->assertNotContains(
            $subscription->id,
            Subscription::query()->dueForRenewal()->pluck('id')->all(),
        );

        $this->expectException(SubscriptionNotRenewableException::class);

        $this->renew->execute($subscription);
    }

    #[Test]
    public function the_renewal_line_bills_the_recurring_price_and_never_a_setup_fee(): void
    {
        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))
            ->priced(9000)
            ->create();

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $plan = $this->renew->execute($subscription);

        $this->assertNotNull($plan);
        $this->assertCount(1, $plan->lines);
        $this->assertSame(InvoiceItemKind::Plan, $plan->lines[0]->kind);
        $this->assertSame('CX-2', $plan->lines[0]->line->description);
        $this->assertSame(1, $plan->lines[0]->line->quantity);
        $this->assertTrue($plan->lines[0]->line->setupFee->isZero());
        $this->assertSame('9.000', $plan->gross()->toDecimalString());
        $this->assertFalse($plan->hasDiscount());
    }

    #[Test]
    public function coupon_cycles_decrement_and_stop_applying_once_exhausted(): void
    {
        $coupon = $this->coupon(['duration_cycles' => 2]);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))
            ->withCoupon($coupon->id, 2)
            ->create();

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));
        $first = $this->renew->execute($subscription);

        $this->assertNotNull($first);
        $this->assertSame('0.100000', $first->percentageDiscount);
        $this->assertSame(1, $first->couponCyclesRemaining);
        $this->assertSame(1, $subscription->refresh()->coupon_cycles_remaining);

        $this->travelTo(CarbonImmutable::parse('2026-03-01 00:00:00'));
        $second = $this->renew->execute($subscription);

        $this->assertNotNull($second);
        $this->assertSame('0.100000', $second->percentageDiscount);
        $this->assertSame(0, $subscription->refresh()->coupon_cycles_remaining);

        $this->travelTo(CarbonImmutable::parse('2026-04-01 00:00:00'));
        $third = $this->renew->execute($subscription);

        $this->assertNotNull($third);
        // Exhausted: the discount stops, and the counter does not go negative.
        $this->assertNull($third->percentageDiscount);
        $this->assertNull($third->couponCode);
        $this->assertFalse($third->hasDiscount());
        $this->assertSame(0, $subscription->refresh()->coupon_cycles_remaining);
    }

    #[Test]
    public function a_coupon_that_does_not_apply_to_renewals_never_discounts_one(): void
    {
        $coupon = $this->coupon(['applies_to_renewals' => false, 'duration_cycles' => null]);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))
            ->withCoupon($coupon->id, null)
            ->create();

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $plan = $this->renew->execute($subscription);

        $this->assertNotNull($plan);
        $this->assertFalse($plan->hasDiscount());
    }

    #[Test]
    public function a_renewal_coupon_in_another_currency_is_dropped_without_burning_a_cycle(): void
    {
        $coupon = Coupon::factory()->fixed(500, 'USD')->create([
            'applies_to_renewals' => true,
            'duration_cycles' => 2,
        ]);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))
            ->priced(9000, 'KWD')
            ->withCoupon($coupon->id, 2)
            ->create();

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $plan = $this->renew->execute($subscription);

        $this->assertNotNull($plan);
        // Currencies are never converted, so a USD coupon simply does not
        // apply to a KWD renewal — and must not spend one of the cycles the
        // customer is still owed.
        $this->assertFalse($plan->hasDiscount());
        $this->assertSame(2, $subscription->refresh()->coupon_cycles_remaining);
    }

    #[Test]
    public function a_renewal_plan_prices_and_issues_through_the_billing_module(): void
    {
        DB::statement("SELECT setval('invoice_number_seq', 1, false)");

        $customer = Customer::factory()->create(['currency' => 'KWD']);
        $coupon = $this->coupon(['duration_cycles' => 1]);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'), BillingPeriod::Monthly)
            ->priced(9000)
            ->withCoupon($coupon->id, 1)
            ->create(['customer_id' => $customer->id]);

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $plan = $this->renew->execute($subscription);
        $this->assertNotNull($plan);

        // This is the handoff the coordinator performs: price the plan's lines,
        // then issue them. Nothing in the subscription module does either.
        $priced = app(PricingEngine::class)->price(
            lines: $plan->pricingLines(),
            taxRate: TaxRate::of('0.15', 'VAT'),
            fixedDiscount: $plan->fixedDiscount,
            percentageDiscount: $plan->percentageDiscount,
            couponCode: $plan->couponCode,
        );

        $invoice = app(IssueInvoice::class)->execute(
            customer: $customer,
            lines: InvoiceLineDraft::zip(
                $priced,
                $plan->pricingLines(),
                InvoiceItemKind::Plan,
                $plan->periodStart,
                $plan->periodEnd,
                $plan->subscriptionId,
            ),
            subscriptionId: $plan->subscriptionId,
        );

        // 9.000 less 10% is 8.100, plus 15% tax is 9.315.
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertSame(900, $invoice->discount_minor);
        $this->assertSame(8100, $invoice->subtotal_minor);
        $this->assertSame(1215, $invoice->tax_minor);
        $this->assertSame(9315, $invoice->total_minor);
        $this->assertSame($subscription->id, trim((string) $invoice->subscription_id));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function coupon(array $overrides = []): Coupon
    {
        return Coupon::factory()->create(array_merge([
            'applies_to_renewals' => true,
            'duration_cycles' => 3,
        ], $overrides));
    }
}
