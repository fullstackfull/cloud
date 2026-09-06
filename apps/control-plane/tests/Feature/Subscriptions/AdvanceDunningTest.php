<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use Lynomia\Modules\Subscriptions\Application\Actions\AdvanceDunning;
use Lynomia\Modules\Subscriptions\Application\Actions\TransitionSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdvanceDunningTest extends TestCase
{
    use RefreshDatabase;

    private AdvanceDunning $dunning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dunning = app(AdvanceDunning::class);

        config()->set('billing.grace_period_days', 7);
        config()->set('billing.termination_after_days', 14);
    }

    #[Test]
    public function dunning_advances_from_active_to_past_due_to_suspended_to_terminated_on_the_configured_schedule(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 09:00:00'));

        $subscription = Subscription::factory()->create();

        $subscription = $this->dunning->recordFailedPayment($subscription);

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->failed_payment_count);
        $this->assertSame('2026-02-08 09:00:00', $subscription->grace_period_ends_at->toDateTimeString());

        // One day short of the deadline the service is still past due, not off.
        $this->travelTo(CarbonImmutable::parse('2026-02-07 09:00:00'));
        $subscription = $this->dunning->execute($subscription);
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);

        $this->travelTo(CarbonImmutable::parse('2026-02-08 09:00:00'));
        $subscription = $this->dunning->execute($subscription);

        $this->assertSame(SubscriptionStatus::Suspended, $subscription->status);
        $this->assertSame('2026-02-08 09:00:00', $subscription->suspended_at->toDateTimeString());

        // Thirteen days suspended: the data is still there.
        $this->travelTo(CarbonImmutable::parse('2026-02-21 09:00:00'));
        $subscription = $this->dunning->execute($subscription);
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->status);

        $this->travelTo(CarbonImmutable::parse('2026-02-22 09:00:00'));
        $subscription = $this->dunning->execute($subscription);

        $this->assertSame(SubscriptionStatus::Terminated, $subscription->status);
        $this->assertSame('2026-02-22 09:00:00', $subscription->ended_at->toDateTimeString());
        // Nothing further is owed, so the renewal worker must never see it again.
        $this->assertNull($subscription->next_invoice_at);
        $this->assertFalse($subscription->auto_renew);
    }

    #[Test]
    public function a_successful_payment_during_the_grace_period_restores_the_subscription_and_resets_the_counter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 09:00:00'));

        $subscription = Subscription::factory()->create();
        $subscription = $this->dunning->recordFailedPayment($subscription);

        $this->travelTo(CarbonImmutable::parse('2026-02-02 09:00:00'));
        $subscription = $this->dunning->recordFailedPayment($subscription);
        $this->assertSame(2, $subscription->failed_payment_count);

        $this->travelTo(CarbonImmutable::parse('2026-02-05 09:00:00'));
        $subscription = $this->dunning->recordSuccessfulPayment($subscription);

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(0, $subscription->failed_payment_count);
        // The clocks are wiped, not left behind: the next failure, months from
        // now, must start its own grace period rather than inherit an expired one.
        $this->assertNull($subscription->grace_period_ends_at);
        $this->assertNull($subscription->suspended_at);
    }

    #[Test]
    public function a_past_due_subscription_still_serves_traffic_and_a_suspended_one_does_not(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 09:00:00'));

        $subscription = $this->dunning->recordFailedPayment(Subscription::factory()->create());

        // An expired card is not non-payment; the service stays up while the
        // customer has time to fix it.
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertTrue($subscription->serviceIsRunning());

        $this->travelTo(CarbonImmutable::parse('2026-02-08 09:00:00'));
        $subscription = $this->dunning->execute($subscription);

        $this->assertSame(SubscriptionStatus::Suspended, $subscription->status);
        $this->assertFalse($subscription->serviceIsRunning());
    }

    #[Test]
    public function a_payment_after_suspension_puts_the_service_back(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 09:00:00'));

        $subscription = $this->dunning->recordFailedPayment(Subscription::factory()->create());

        $this->travelTo(CarbonImmutable::parse('2026-02-08 09:00:00'));
        $subscription = $this->dunning->execute($subscription);
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->status);

        $this->travelTo(CarbonImmutable::parse('2026-02-10 09:00:00'));
        $subscription = $this->dunning->recordSuccessfulPayment($subscription);

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->serviceIsRunning());
        $this->assertSame(0, $subscription->failed_payment_count);
    }

    #[Test]
    public function a_retried_payment_failure_does_not_extend_the_grace_period(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 09:00:00'));

        $subscription = $this->dunning->recordFailedPayment(Subscription::factory()->create());

        // The retry schedule is 24, 72 and 168 hours; if each attempt restarted
        // the clock the subscription would never reach suspension.
        $this->travelTo(CarbonImmutable::parse('2026-02-04 09:00:00'));
        $subscription = $this->dunning->recordFailedPayment($subscription);

        $this->assertSame(2, $subscription->failed_payment_count);
        $this->assertSame('2026-02-08 09:00:00', $subscription->grace_period_ends_at->toDateTimeString());

        $this->travelTo(CarbonImmutable::parse('2026-02-08 09:00:00'));
        $this->assertSame(
            SubscriptionStatus::Suspended,
            $this->dunning->execute($subscription)->status,
        );
    }

    #[Test]
    public function dunning_refuses_to_act_on_a_subscription_that_has_already_ended(): void
    {
        $subscription = Subscription::factory()->cancelled()->create();

        $this->expectException(IllegalStateTransitionException::class);

        $this->dunning->recordFailedPayment($subscription);
    }

    #[Test]
    public function a_past_due_subscription_with_no_grace_clock_is_given_one_rather_than_stalling(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 09:00:00'));

        // Reached past_due without a recorded failure — an operator, or a
        // webhook that moved the status without recording the failure.
        $subscription = Subscription::factory()->status(SubscriptionStatus::PastDue)->create();
        $this->assertNull($subscription->grace_period_ends_at);

        $subscription = $this->dunning->execute($subscription);

        // Without a clock the sweep would ask "has the deadline passed?",
        // answer no for ever, and serve an unpaying customer indefinitely.
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame('2026-02-08 09:00:00', $subscription->grace_period_ends_at->toDateTimeString());

        $this->travelTo(CarbonImmutable::parse('2026-02-08 09:00:00'));

        $this->assertSame(
            SubscriptionStatus::Suspended,
            $this->dunning->execute($subscription)->status,
        );
    }

    #[Test]
    public function a_stale_caller_copy_does_not_suppress_a_real_transition(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 09:00:00'));

        $subscription = Subscription::factory()->create();

        // The copy a queued job is holding: read while the subscription was
        // still past due, and only handed to the action after a payment had
        // already returned the row to active.
        $stale = Subscription::query()->findOrFail($subscription->id);
        $stale->status = SubscriptionStatus::PastDue;

        $moved = app(TransitionSubscription::class)->execute($stale, SubscriptionStatus::PastDue);

        // Answering "already past due" from the caller's copy would leave an
        // active subscription outside dunning entirely, still being served and
        // with no grace clock ever started.
        $this->assertSame(SubscriptionStatus::PastDue, $moved->status);
        $this->assertSame(
            SubscriptionStatus::PastDue,
            Subscription::query()->findOrFail($subscription->id)->status,
        );
    }

    #[Test]
    public function a_stale_caller_copy_does_not_refuse_a_transition_the_row_allows(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 09:00:00'));

        $subscription = Subscription::factory()->create();

        // The mirror image: a copy read while the subscription looked
        // terminal, against a row that is in fact perfectly live.
        $stale = Subscription::query()->findOrFail($subscription->id);
        $stale->status = SubscriptionStatus::Cancelled;

        $moved = app(TransitionSubscription::class)->execute($stale, SubscriptionStatus::PastDue);

        $this->assertSame(SubscriptionStatus::PastDue, $moved->status);
    }

    #[Test]
    public function the_sweep_leaves_a_healthy_subscription_alone(): void
    {
        $subscription = Subscription::factory()->create();

        $swept = $this->dunning->execute($subscription);

        $this->assertSame(SubscriptionStatus::Active, $swept->status);
        $this->assertSame(0, $swept->failed_payment_count);
    }
}
