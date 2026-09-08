<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Application\Jobs\DeliverNotification;
use Lynomia\Modules\Notifications\Domain\Enums\DeliveryStatus;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationCategory;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use Lynomia\Modules\Notifications\Infrastructure\Models\NotificationPreference;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Telling a customer something, exactly once.
 *
 * Every listener that raises a notification runs on a queue with retries, and
 * several are driven by provider webhooks that arrive more than once by
 * design. "Once" is the entire difficulty, and it is defended by a unique
 * index rather than by a check, because two workers can both look, both find
 * nothing, and both insert.
 */
final class NotifyCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([DeliverNotification::class]);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->user = User::factory()->create(['locale' => 'en']);
    }

    #[Test]
    public function a_notification_is_recorded_and_its_deliveries_queued(): void
    {
        $notification = $this->notify(NotificationType::ServiceReady, 'service-ready:01JSERVICE');

        $this->assertNotNull($notification);
        $this->assertSame(NotificationCategory::Service, $notification->category);
        $this->assertNull($notification->read_at);

        // In-app and email: ServiceReady is one a customer wants to know about
        // away from the portal.
        $channels = $notification->deliveries()->pluck('channel')->map(fn ($c) => $c->value)->sort()->values()->all();
        $this->assertSame(['email', 'in_app'], $channels);

        Queue::assertPushed(DeliverNotification::class, 2);
    }

    #[Test]
    public function the_same_event_twice_produces_one_notification(): void
    {
        /*
         * The case this exists for: a provisioning job that succeeds on its
         * third attempt must not tell the customer their server is ready three
         * times.
         */
        $first = $this->notify(NotificationType::ServiceReady, 'service-ready:01JSERVICE');
        $second = $this->notify(NotificationType::ServiceReady, 'service-ready:01JSERVICE');

        $this->assertNotNull($first);
        $this->assertNull($second, 'A retried event raised a second notification.');

        $this->assertSame(1, Notification::query()->count());
        $this->assertSame(2, NotificationDelivery::query()->count());

        // And no second round of delivery jobs, which is where the duplicate
        // emails would actually have come from.
        Queue::assertPushed(DeliverNotification::class, 2);
    }

    #[Test]
    public function two_different_occurrences_of_the_same_type_both_arrive(): void
    {
        // The key carries the occurrence, so a customer with two servers gets
        // two "ready" messages — which is the correct outcome and the reason
        // the key is not just the type and the customer.
        $this->notify(NotificationType::ServiceReady, 'service-ready:01JSERVICE-A');
        $this->notify(NotificationType::ServiceReady, 'service-ready:01JSERVICE-B');

        $this->assertSame(2, Notification::query()->count());
    }

    #[Test]
    public function a_customer_can_silence_service_email_but_not_the_inbox(): void
    {
        NotificationPreference::query()->create([
            'user_id' => $this->user->getKey(),
            'category' => NotificationCategory::Service,
            'channel' => NotificationChannel::Email,
            'enabled' => false,
        ]);

        $notification = $this->notify(NotificationType::ServiceReady, 'service-ready:01JSERVICE');

        $this->assertNotNull($notification);

        /*
         * The inbox survives. It is the platform's own record of what it did
         * to somebody's account, and a customer who silenced it would be
         * looking at an empty page after their server was terminated.
         */
        $this->assertSame(
            ['in_app'],
            $notification->deliveries()->pluck('channel')->map(fn ($c) => $c->value)->all(),
        );
    }

    #[Test]
    public function billing_email_cannot_be_silenced_even_with_a_preference_row(): void
    {
        /*
         * The row can exist — written by an older version, or by hand — and it
         * must not be honoured. A customer who silenced "your card was
         * declined" and is then suspended has a complaint the platform cannot
         * answer.
         */
        NotificationPreference::query()->create([
            'user_id' => $this->user->getKey(),
            'category' => NotificationCategory::Billing,
            'channel' => NotificationChannel::Email,
            'enabled' => false,
        ]);

        $notification = $this->notify(NotificationType::PaymentFailed, 'payment-failed:01JTXN');

        $this->assertNotNull($notification);
        $this->assertContains(
            'email',
            $notification->deliveries()->pluck('channel')->map(fn ($c) => $c->value)->all(),
        );
    }

    #[Test]
    public function security_email_cannot_be_silenced_either(): void
    {
        NotificationPreference::query()->create([
            'user_id' => $this->user->getKey(),
            'category' => NotificationCategory::Security,
            'channel' => NotificationChannel::Email,
            'enabled' => false,
        ]);

        $notification = $this->notify(NotificationType::PasswordChanged, 'password-changed:1');

        $this->assertNotNull($notification);
        $this->assertContains(
            'email',
            $notification->deliveries()->pluck('channel')->map(fn ($c) => $c->value)->all(),
        );
    }

    #[Test]
    public function an_unimplemented_channel_never_gets_a_delivery_row(): void
    {
        /*
         * SMS, WhatsApp and push are declared so the extension point is
         * visible. A declared-but-unimplemented channel must not produce a row
         * that sits pending for ever waiting on a worker that does not exist.
         */
        foreach (NotificationType::cases() as $type) {
            foreach ($type->defaultChannels() as $channel) {
                $this->assertTrue(
                    $channel->isImplemented(),
                    sprintf('%s is delivered on the unimplemented channel %s.', $type->value, $channel->value),
                );
            }
        }
    }

    #[Test]
    public function every_notification_type_has_copy_in_both_languages(): void
    {
        /*
         * A type added without translations renders its own key to a customer.
         * Checked here rather than left to a reviewer, because the failure is
         * invisible in English if English happens to be written.
         */
        $missing = [];

        foreach (NotificationType::cases() as $type) {
            foreach (['en', 'ar'] as $locale) {
                foreach (['title', 'body'] as $part) {
                    $key = 'notifications.'.$type->value.'.'.$part;

                    if (! trans()->has($key, $locale)) {
                        $missing[] = $locale.': '.$key;
                    }
                }
            }
        }

        $this->assertSame([], $missing, "Notification copy is missing:\n  ".implode("\n  ", $missing));
    }

    #[Test]
    public function deliveries_start_pending_so_nothing_reads_as_sent_before_it_is(): void
    {
        $notification = $this->notify(NotificationType::ServiceReady, 'service-ready:01JSERVICE');

        $this->assertNotNull($notification);

        foreach ($notification->deliveries as $delivery) {
            $this->assertSame(DeliveryStatus::Pending, $delivery->status);
            $this->assertNull($delivery->sent_at);
        }
    }

    private function notify(NotificationType $type, string $key): ?Notification
    {
        return app(NotifyCustomer::class)->execute(
            customerId: (string) $this->customer->getKey(),
            type: $type,
            idempotencyKey: $key,
            data: ['service' => 'web-kw-01'],
            link: '/vps',
            userId: (string) $this->user->getKey(),
        );
    }
}
