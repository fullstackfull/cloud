<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Subscriptions\Application\Actions\SweepSubscriptionLifecycle;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The clocks that end a subscription, rather than the one that renews it.
 *
 * Both sequences were fully implemented and unreachable: a cancellation date
 * was recorded and never acted on, and the dunning schedule — past due, then
 * suspended, then terminated — was written, tested and never advanced by
 * anything. A subscription cancelled by a customer stayed active for ever, and
 * a subscription that stopped paying ran unpaid for ever.
 */
final class SweepSubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private SweepSubscriptionLifecycle $sweep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sweep = app(SweepSubscriptionLifecycle::class);
        $this->travelTo(CarbonImmutable::parse('2026-03-01 12:00:00'));
    }

    private function subscription(): Subscription
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        return Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-02-01 00:00:00'), BillingPeriod::Monthly)
            ->priced(9_000)
            ->create(['customer_id' => $customer->id]);
    }

    #[Test]
    public function a_cancellation_whose_date_has_arrived_is_applied(): void
    {
        $subscription = $this->subscription();
        $subscription->forceFill(['cancel_at' => CarbonImmutable::parse('2026-03-01 06:00:00')])->save();

        $result = $this->sweep->execute();

        $this->assertSame(1, $result->cancelled);
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
    }

    #[Test]
    public function a_cancellation_still_in_the_future_is_left_running(): void
    {
        $subscription = $this->subscription();
        $subscription->forceFill(['cancel_at' => CarbonImmutable::parse('2026-03-25 00:00:00')])->save();

        $result = $this->sweep->execute();

        $this->assertSame(0, $result->cancellationsConsidered);
        // The customer paid for this period and is entitled to the rest of it.
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
    }

    #[Test]
    public function a_past_due_subscription_whose_grace_has_expired_is_suspended(): void
    {
        $subscription = $this->subscription();
        $subscription->forceFill([
            'status' => SubscriptionStatus::PastDue,
            'failed_payment_count' => 1,
            'grace_period_ends_at' => CarbonImmutable::parse('2026-02-25 00:00:00'),
        ])->save();

        $result = $this->sweep->execute();

        $this->assertSame(1, $result->advanced);
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->refresh()->status);
    }

    #[Test]
    public function a_past_due_subscription_still_inside_its_grace_keeps_serving(): void
    {
        $subscription = $this->subscription();
        $subscription->forceFill([
            'status' => SubscriptionStatus::PastDue,
            'failed_payment_count' => 1,
            'grace_period_ends_at' => CarbonImmutable::parse('2026-03-20 00:00:00'),
        ])->save();

        $result = $this->sweep->execute();

        // Most failed recurring payments are expired cards, not customers who
        // have stopped paying. Cutting the service on the first decline is how
        // a recoverable account becomes a lost one.
        $this->assertSame(0, $result->advanced);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);
    }

    #[Test]
    public function suspension_and_termination_never_happen_in_the_same_run(): void
    {
        $subscription = $this->subscription();
        $subscription->forceFill([
            'status' => SubscriptionStatus::PastDue,
            'failed_payment_count' => 1,
            // Long enough ago that both clocks have expired.
            'grace_period_ends_at' => CarbonImmutable::parse('2026-01-01 00:00:00'),
        ])->save();

        $this->sweep->execute();

        // One step per run: suspension is what starts the termination clock, so
        // collapsing the two would destroy a customer's data in the same
        // instant their service went off, with no window for anybody to notice.
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->refresh()->status);
    }

    #[Test]
    public function a_subscription_cancelled_in_this_run_is_not_also_dunned(): void
    {
        $subscription = $this->subscription();
        $subscription->forceFill([
            'status' => SubscriptionStatus::PastDue,
            'failed_payment_count' => 1,
            'grace_period_ends_at' => CarbonImmutable::parse('2026-01-01 00:00:00'),
            'cancel_at' => CarbonImmutable::parse('2026-02-28 00:00:00'),
        ])->save();

        $result = $this->sweep->execute();

        $this->assertSame(1, $result->cancelled);
        $this->assertSame(0, $result->advanced);
        // Cancelled, not suspended: the customer ended this themselves, and a
        // suspension record would describe a history that did not happen.
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
    }
}
