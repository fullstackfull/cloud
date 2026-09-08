<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Application\Jobs\DeliverNotification;
use Lynomia\Modules\Notifications\Domain\Contracts\NotificationDeliveryChannel;
use Lynomia\Modules\Notifications\Domain\Enums\DeliveryStatus;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Mail\NotificationMail;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use Lynomia\Modules\Notifications\Infrastructure\Registries\NotificationChannelRegistry;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Getting a notification down a channel, and what happens when it will not go.
 *
 * The governing rule is that notification is a secondary operation. A mail
 * server refusing must never roll back a payment, fail a provisioning job, or
 * remove the customer's in-app record that the thing happened.
 */
final class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Notification $notification;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create(['email' => 'owner@example.test', 'locale' => 'en']);

        $this->notification = Notification::factory()->create([
            'customer_id' => $customer->getKey(),
            'user_id' => $user->getKey(),
            'type' => NotificationType::ServiceReady,
            'category' => NotificationType::ServiceReady->category(),
            'data' => ['service' => 'web-kw-01'],
        ]);
    }

    #[Test]
    public function a_sent_email_is_recorded_with_where_it_went(): void
    {
        $delivery = $this->delivery(NotificationChannel::Email);

        $this->deliverNow($delivery);

        $delivery->refresh();

        $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        $this->assertSame('owner@example.test', $delivery->destination);
        $this->assertNotNull($delivery->sent_at);
        $this->assertSame(1, $delivery->attempts);

        Mail::assertSent(NotificationMail::class);
    }

    #[Test]
    public function the_email_is_rendered_in_the_recipients_language(): void
    {
        /*
         * A queue worker has no request behind it, so the application locale
         * is whatever the process booted with — English. Sending an Arabic
         * customer English mail because a worker had no request is the exact
         * failure the stored-facts design exists to prevent.
         */
        User::query()->whereKey($this->notification->user_id)->update(['locale' => 'ar']);

        $this->deliverNow($this->delivery(NotificationChannel::Email));

        Mail::assertSent(NotificationMail::class, static function (NotificationMail $mail): bool {
            return $mail->rendered->locale === 'ar'
                && str_contains($mail->rendered->title, 'جاهز');
        });
    }

    #[Test]
    public function the_body_carries_the_facts_rather_than_a_stored_sentence(): void
    {
        $this->deliverNow($this->delivery(NotificationChannel::Email));

        Mail::assertSent(NotificationMail::class, static function (NotificationMail $mail): bool {
            // The hostname came from `data`, interpolated into one whole
            // translated sentence rather than concatenated around it.
            return str_contains($mail->rendered->title, 'web-kw-01');
        });
    }

    #[Test]
    public function a_recipient_with_no_address_is_an_absence_and_not_a_bounce(): void
    {
        /*
         * Recorded as failed with a reason, and never retried: three attempts
         * cannot invent an email address, and counting an absence as a bounce
         * hides the real bounces on the operator screen.
         */
        Queue::fake([DeliverNotification::class]);

        User::query()->whereKey($this->notification->user_id)->delete();
        $this->notification->customer()->update(['billing_email' => null]);
        $this->notification->refresh();

        $delivery = $this->delivery(NotificationChannel::Email);

        $this->deliverNow($delivery);

        $delivery->refresh();

        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame('no destination for this channel', $delivery->failure_reason);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_refused_send_is_retried_and_stays_queued_until_the_last_attempt(): void
    {
        Queue::fake([DeliverNotification::class]);

        $this->swapChannel(NotificationChannel::Email, throws: 'the mail server said 421');

        $delivery = $this->delivery(NotificationChannel::Email);

        $this->deliverNow($delivery);
        $delivery->refresh();

        // Still queued, not failed: the operator screen has to distinguish
        // "still trying" from "gave up".
        $this->assertSame(DeliveryStatus::Queued, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringContainsString('421', (string) $delivery->failure_reason);
        $this->assertNull($delivery->failed_at);

        Queue::assertPushed(DeliverNotification::class, 1);
    }

    #[Test]
    public function the_last_attempt_gives_up_without_putting_a_row_in_failed_jobs(): void
    {
        /*
         * Nothing throws into the queue's failure machinery. There is nothing
         * an operator can do with fifty failed_jobs rows saying an address
         * bounced, and the delivery row with its reason is a better record
         * than a stack trace.
         */
        Queue::fake([DeliverNotification::class]);

        $this->swapChannel(NotificationChannel::Email, throws: 'mailbox unavailable');

        $delivery = $this->delivery(NotificationChannel::Email);
        $delivery->forceFill(['attempts' => 2])->save();

        $this->deliverNow($delivery);
        $delivery->refresh();

        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame(3, $delivery->attempts);
        $this->assertNotNull($delivery->failed_at);

        // No further attempt queued.
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_failed_email_leaves_the_in_app_copy_alone(): void
    {
        // The notification is the customer's record that the thing happened.
        // A mail server refusing must not take it away.
        $this->swapChannel(NotificationChannel::Email, throws: 'nope');

        $email = $this->delivery(NotificationChannel::Email);
        $inApp = $this->delivery(NotificationChannel::InApp);

        $this->deliverNow($email);
        $this->deliverNow($inApp);

        $this->assertSame(DeliveryStatus::Failed, $email->refresh()->status);
        $this->assertSame(DeliveryStatus::Sent, $inApp->refresh()->status);
        $this->assertNotNull($this->notification->fresh());
    }

    #[Test]
    public function a_delivery_that_already_finished_is_left_alone(): void
    {
        // A redelivered job must not send a second copy of a message that has
        // already gone.
        $delivery = $this->delivery(NotificationChannel::Email);
        $delivery->forceFill(['status' => DeliveryStatus::Sent, 'sent_at' => now()])->save();

        $this->deliverNow($delivery);

        Mail::assertNothingSent();

        // The attempt counter did not move either: the job returned before
        // touching anything.
        $this->assertSame(0, $delivery->refresh()->attempts);
    }

    #[Test]
    public function every_implemented_channel_is_registered(): void
    {
        /*
         * The lesson Phase 29 paid for: five provisioning handlers were
         * written, tested and unregistered, and every one presented to a
         * customer as a working button.
         */
        $registry = app(NotificationChannelRegistry::class);

        foreach (NotificationChannel::implemented() as $channel) {
            $this->assertTrue(
                $registry->has($channel),
                sprintf('%s is declared implemented and has no registered delivery channel.', $channel->value),
            );
            $this->assertSame($channel, $registry->for($channel)->channel());
        }
    }

    #[Test]
    public function an_unimplemented_channel_fails_loudly_rather_than_doing_nothing(): void
    {
        $this->expectException(RuntimeException::class);

        app(NotificationChannelRegistry::class)->for(NotificationChannel::Sms);
    }

    private function delivery(NotificationChannel $channel): NotificationDelivery
    {
        return NotificationDelivery::query()->create([
            'notification_id' => $this->notification->getKey(),
            'channel' => $channel,
            'status' => DeliveryStatus::Pending,
        ]);
    }

    private function deliverNow(NotificationDelivery $delivery): void
    {
        (new DeliverNotification((string) $delivery->getKey()))->handle(
            app(NotificationChannelRegistry::class),
            app(SecretRedactor::class),
        );
    }

    /**
     * Replaces one channel with a double that always refuses.
     */
    private function swapChannel(NotificationChannel $channel, string $throws): void
    {
        $registry = app(NotificationChannelRegistry::class);

        $registry->register(new class($channel, $throws) implements NotificationDeliveryChannel
        {
            public function __construct(
                private readonly NotificationChannel $channel,
                private readonly string $message,
            ) {}

            public function channel(): NotificationChannel
            {
                return $this->channel;
            }

            public function canReach(Notification $notification): bool
            {
                return true;
            }

            public function deliver(Notification $notification): string
            {
                throw new RuntimeException($this->message);
            }
        });
    }
}
