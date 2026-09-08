<?php

declare(strict_types=1);

namespace Tests\Feature\Termination;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Application\Actions\BeginRetentionWindow;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A customer leaving, from the click to the day the data goes.
 *
 * Cancelling has always stopped the billing. What it did not do was mean
 * anything to the thing being paid for: the machine kept running, the disk
 * kept the data, and nothing ever ended. These are the tests for the other
 * half — and for the two dates a departing customer is entitled to know.
 */
final class LeavingThePlatformTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    private Subscription $subscription;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->owner = User::factory()->create();

        $this->customer->members()->create([
            'user_id' => $this->owner->id,
            'role' => CustomerRole::Owner,
            'accepted_at' => now(),
        ]);

        $this->subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'status' => SubscriptionStatus::Active,
            'current_period_end' => CarbonImmutable::now()->addDays(10),
        ]);

        $this->service = Service::factory()->active()->create([
            'customer_id' => $this->customer->id,
            'kind' => 'vps',
            'subscription_id' => $this->subscription->getKey(),
            'label' => 'web-kw-01',
        ]);
    }

    #[Test]
    public function scheduling_a_cancellation_tells_the_customer_when_it_ends(): void
    {
        $this->cancel()->assertOk();

        /*
         * The message nothing produced before. A scheduled cancellation moves
         * no status, so the listener that watches status changes never saw it,
         * and the customer heard nothing at all until the day their service
         * stopped.
         */
        $notification = Notification::query()
            ->where('type', NotificationType::CancellationScheduled->value)
            ->firstOrFail();

        $this->assertSame('web-kw-01', $notification->data['service'] ?? null);
        $this->assertNotSame('', $notification->data['date'] ?? '');

        // And the service keeps running: the customer paid for this period.
        $this->assertSame(ServiceStatus::Active, $this->service->fresh()?->status);
    }

    #[Test]
    public function cancelling_twice_does_not_move_the_date_the_customer_was_told(): void
    {
        $this->cancel()->assertOk();

        $first = $this->subscription->fresh()?->cancel_at;

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
        $this->cancel()->assertOk();
        CarbonImmutable::setTestNow();

        $this->assertEquals($first, $this->subscription->fresh()?->cancel_at);

        // Told once, too. A customer who clicks cancel twice has arranged one
        // cancellation.
        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::CancellationScheduled->value)->count(),
        );
    }

    #[Test]
    public function cancelling_immediately_needs_the_subscription_id_typed_back(): void
    {
        $this->immediately([])->assertStatus(422);

        $this->immediately(['confirm_subscription_id' => 'not-this-one'])->assertStatus(422);

        $this->immediately([
            'confirm_subscription_id' => (string) $this->subscription->getKey(),
        ])->assertOk();
    }

    #[Test]
    public function an_immediate_cancellation_stops_the_service_and_starts_the_window(): void
    {
        $this->immediately([
            'confirm_subscription_id' => (string) $this->subscription->getKey(),
        ])->assertOk();

        $service = $this->service->fresh();

        $this->assertNotNull($service);
        $this->assertSame(ServiceStatus::Suspended, $service->status);

        // The date, written down rather than computed later: the customer is
        // about to be told it, and a promise that moves when a config file
        // changes is not a promise.
        $this->assertNotNull($service->retention_ends_at);
        $this->assertSame(BeginRetentionWindow::BY_CUSTOMER, $service->ended_reason);

        $notification = Notification::query()
            ->where('type', NotificationType::ServiceEnded->value)
            ->firstOrFail();

        $this->assertSame(
            $service->retention_ends_at->toDateString(),
            $notification->data['retention_ends'] ?? null,
        );
    }

    #[Test]
    public function the_customer_can_see_the_date_their_data_goes(): void
    {
        $this->immediately([
            'confirm_subscription_id' => (string) $this->subscription->getKey(),
        ])->assertOk();

        $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->getJson('/api/v1/services/'.$this->service->getKey())
            ->assertOk()
            ->assertJsonPath('data.ended_reason', BeginRetentionWindow::BY_CUSTOMER)
            // A portal that knows the deadline and does not show it is keeping
            // it to itself.
            ->assertJsonStructure(['data' => ['retention_ends_at']]);
    }

    #[Test]
    public function a_departing_customers_backups_are_held_through_the_window(): void
    {
        $backup = Backup::factory()->create([
            'customer_id' => $this->customer->id,
            'service_id' => $this->service->getKey(),
            'state' => BackupState::Succeeded,
            // Due to be swept away tomorrow under the ordinary policy.
            'expires_at' => CarbonImmutable::now()->addDay(),
        ]);

        $this->immediately([
            'confirm_subscription_id' => (string) $this->subscription->getKey(),
        ])->assertOk();

        /*
         * `protected_until` had no producer at all until this path existed:
         * the deletion work wrote the column and the hold it describes was
         * nobody's job. Without it, the copies of a departing customer's data
         * are removed by the ordinary retention sweep days before the window
         * they were promised closes.
         */
        $this->assertNotNull($backup->fresh()?->protected_until);
        $this->assertTrue(
            $backup->fresh()?->protected_until?->greaterThan(CarbonImmutable::now()->addDays(20)) ?? false,
        );
    }

    #[Test]
    public function paying_after_a_suspension_clears_the_destruction_date(): void
    {
        // Suspended for non-payment: the same window starts, with a different
        // reason on it.
        $this->service->forceFill([
            'status' => ServiceStatus::Suspended,
            'suspended_at' => CarbonImmutable::now(),
        ])->save();

        app(BeginRetentionWindow::class)->execute($this->service, BeginRetentionWindow::BY_NON_PAYMENT);

        $this->assertNotNull($this->service->fresh()?->retention_ends_at);

        app(BeginRetentionWindow::class)->cancel($this->service);

        // Paid, and staying. The date stops existing rather than sitting in
        // the row where the next sweep would read it.
        $this->assertNull($this->service->fresh()?->retention_ends_at);
        $this->assertNull($this->service->fresh()?->ended_reason);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function immediately(array $payload): TestResponse
    {
        return $this->actingAs($this->owner)
            ->withHeaders($this->acting())
            ->postJson($this->url(), [...$payload, 'immediately' => true]);
    }

    private function cancel(): TestResponse
    {
        return $this->actingAs($this->owner)->withHeaders($this->acting())->postJson($this->url(), []);
    }

    /**
     * @return array<string, string>
     */
    private function acting(): array
    {
        return ['X-Lynomia-Customer' => (string) $this->customer->getKey()];
    }

    private function url(): string
    {
        return '/api/v1/subscriptions/'.$this->subscription->getKey().'/cancel';
    }
}
