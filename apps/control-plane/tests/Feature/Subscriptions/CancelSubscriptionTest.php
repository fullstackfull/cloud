<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Subscriptions\Application\Actions\CancelSubscription;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CancelSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private CancelSubscription $cancel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cancel = app(CancelSubscription::class);
    }

    #[Test]
    public function a_scheduled_cancellation_serves_out_the_paid_period_and_then_stops(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-15 00:00:00'));

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))
            ->create();

        $subscription = $this->cancel->execute($subscription);

        // The customer paid for January and keeps it.
        $this->assertTrue($subscription->serviceIsRunning());
        $this->assertSame('2026-02-01 00:00:00', $subscription->cancel_at->toDateTimeString());

        // And once the date they were told arrives, the service is off — even
        // if the cancellation sweep has not run yet, which is the window in
        // which a cancelled customer would otherwise be served for free.
        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:00'));

        $this->assertFalse($subscription->refresh()->serviceIsRunning());
    }

    #[Test]
    public function the_sweep_cancels_a_subscription_once_its_scheduled_date_arrives(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-15 00:00:00'));

        $subscription = $this->cancel->execute(
            Subscription::factory()->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))->create(),
        );

        $this->travelTo(CarbonImmutable::parse('2026-01-31 23:00:00'));
        $this->assertEmpty(Subscription::query()->dueForCancellation()->pluck('id')->all());
        $this->assertSame(
            SubscriptionStatus::Active,
            $this->cancel->applyScheduled($subscription)->status,
        );

        $this->travelTo(CarbonImmutable::parse('2026-02-01 00:00:30'));

        $this->assertContains(
            $subscription->id,
            Subscription::query()->dueForCancellation()->pluck('id')->all(),
        );

        $cancelled = $this->cancel->applyScheduled($subscription);

        $this->assertSame(SubscriptionStatus::Cancelled, $cancelled->status);
        // Stamped at the date the customer was promised, not at whatever hour
        // the sweep happened to run.
        $this->assertSame('2026-02-01 00:00:00', $cancelled->ended_at->toDateTimeString());
        $this->assertSame('2026-02-01 00:00:00', $cancelled->cancelled_at->toDateTimeString());
        $this->assertNull($cancelled->next_invoice_at);
        $this->assertFalse($cancelled->serviceIsRunning());
    }

    #[Test]
    public function revoking_a_cancellation_before_the_sweep_runs_keeps_the_subscription(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-15 00:00:00'));

        $subscription = $this->cancel->execute(
            Subscription::factory()->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))->create(),
        );

        $this->cancel->revoke($subscription);

        $this->travelTo(CarbonImmutable::parse('2026-02-02 00:00:00'));

        // The sweep is holding the copy it selected before the revocation
        // committed; it must re-read rather than switch off a service the
        // customer has just kept.
        $swept = $this->cancel->applyScheduled($subscription);

        $this->assertSame(SubscriptionStatus::Active, $swept->status);
        $this->assertTrue($swept->serviceIsRunning());
    }

    #[Test]
    public function an_immediate_cancellation_stops_the_service_at_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-15 00:00:00'));

        $subscription = $this->cancel->execute(
            Subscription::factory()->startingOn(CarbonImmutable::parse('2026-01-01 00:00:00'))->create(),
            immediately: true,
        );

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertFalse($subscription->serviceIsRunning());
        $this->assertNull($subscription->next_invoice_at);
    }
}
