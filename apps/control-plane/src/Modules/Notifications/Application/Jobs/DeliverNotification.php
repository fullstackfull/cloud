<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lynomia\Modules\Notifications\Domain\Enums\DeliveryStatus;
use Lynomia\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use Lynomia\Modules\Notifications\Infrastructure\Registries\NotificationChannelRegistry;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * Gets one notification down one channel.
 *
 * ---------------------------------------------------------------------------
 * Its own queue, deliberately
 * ---------------------------------------------------------------------------
 *
 * Notifications must never sit behind provisioning. A fleet-wide reconciliation
 * that filled the infrastructure queue would otherwise delay every "your server
 * is ready" on the platform, and the customer waiting for that mail is the one
 * who just paid.
 *
 * ---------------------------------------------------------------------------
 * Failure is recorded, never escalated
 * ---------------------------------------------------------------------------
 *
 * The last attempt marks the delivery failed and returns. Nothing here throws
 * into the queue's failure machinery, because there is nothing an operator can
 * do with fifty failed_jobs rows saying an address bounced — and the delivery
 * row, with its reason, is a better record than a stack trace. The operator
 * screen reads those rows.
 *
 * That is also why this job never touches the notification itself. The in-app
 * copy exists the moment NotifyCustomer wrote it; the email failing must not
 * remove the customer's record that the thing happened.
 */
final class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string QUEUE = 'notifications';

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        private readonly string $deliveryId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Widening gaps: a mail server that refused once is often still refusing a
     * few seconds later, and a customer is better served by the message
     * arriving in a minute than by three refusals in ten seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(NotificationChannelRegistry $channels, SecretRedactor $redactor): void
    {
        $delivery = NotificationDelivery::query()->with('notification')->find($this->deliveryId);

        if ($delivery === null || $delivery->status->isTerminal()) {
            // Already sent, already given up on, or deleted with its
            // notification. All three mean doing nothing is correct.
            return;
        }

        $notification = $delivery->notification;
        $channel = $channels->for($delivery->channel);

        if (! $channel->canReach($notification)) {
            /*
             * No address, no device, nothing to send to. Recorded as failed
             * with a reason rather than retried: three attempts cannot invent
             * an email address, and an absence counted as a bounce hides the
             * real bounces on the operator screen.
             */
            $delivery->forceFill([
                'status' => DeliveryStatus::Failed,
                'failed_at' => now(),
                'attempts' => $delivery->attempts + 1,
                'failure_reason' => 'no destination for this channel',
            ])->save();

            return;
        }

        $delivery->forceFill([
            'status' => DeliveryStatus::Queued,
            'attempts' => $delivery->attempts + 1,
        ])->save();

        try {
            $destination = $channel->deliver($notification);
        } catch (Throwable $e) {
            $this->recordFailure($delivery, $redactor->redactString($e->getMessage()));

            return;
        }

        $delivery->forceFill([
            'status' => DeliveryStatus::Sent,
            'sent_at' => now(),
            'destination' => $destination,
        ])->save();
    }

    private function recordFailure(NotificationDelivery $delivery, string $reason): void
    {
        $isLastAttempt = $delivery->attempts >= $this->tries;

        $delivery->forceFill([
            // Left Queued between attempts so the operator screen distinguishes
            // "still trying" from "gave up", which are different conversations.
            'status' => $isLastAttempt ? DeliveryStatus::Failed : DeliveryStatus::Queued,
            'failed_at' => $isLastAttempt ? now() : null,
            'failure_reason' => mb_substr($reason, 0, 500),
        ])->save();

        if (! $isLastAttempt) {
            // Re-queued by hand rather than by throwing: throwing would put a
            // row in failed_jobs on the final attempt, and a bounced address is
            // not a job failure an operator should be paged about.
            self::dispatch($this->deliveryId)->delay(now()->addSeconds($this->backoff()[$delivery->attempts - 1] ?? 120));
        }
    }
}
