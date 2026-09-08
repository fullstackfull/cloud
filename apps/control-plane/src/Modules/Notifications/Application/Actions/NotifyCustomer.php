<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Application\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Notifications\Application\Jobs\DeliverNotification;
use Lynomia\Modules\Notifications\Domain\Enums\DeliveryStatus;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use Lynomia\Modules\Notifications\Infrastructure\Models\NotificationPreference;

/**
 * Tell a customer something, once.
 *
 * ---------------------------------------------------------------------------
 * Once is the hard part
 * ---------------------------------------------------------------------------
 *
 * Every listener that calls this runs on a queue with retries, and several are
 * driven by provider webhooks that arrive more than once by design. A
 * provisioning job that succeeds on its third attempt must tell the customer
 * their server is ready exactly one time.
 *
 * The defence is a unique index, not a check. Two workers can both look for an
 * existing row, both find nothing, and both insert; the database is the only
 * thing that can arbitrate. The caller supplies a key built from the event, the
 * customer, the subject and the occurrence — never from a timestamp, which
 * would make every retry unique and defeat the whole mechanism.
 *
 * ---------------------------------------------------------------------------
 * Delivery is somebody else's problem
 * ---------------------------------------------------------------------------
 *
 * This writes the notification and queues the deliveries; it never talks to a
 * mail server. Notification is a secondary operation, and a slow SMTP host must
 * not sit inside the transaction that captured a payment or the job that built
 * a machine. That is also why nothing here throws on a delivery problem: the
 * customer being told is important, and it is not as important as the money and
 * the machine being correct.
 */
final readonly class NotifyCustomer
{
    /**
     * @param  array<string, mixed>  $data  Facts, not prose. The sentence is built at read time.
     * @return Notification|null the notification, or null when this exact one was already raised
     */
    public function execute(
        string $customerId,
        NotificationType $type,
        string $idempotencyKey,
        ?Model $subject = null,
        array $data = [],
        ?string $link = null,
        ?string $userId = null,
    ): ?Notification {
        try {
            $notification = DB::transaction(fn (): Notification => Notification::query()->create([
                'customer_id' => $customerId,
                'user_id' => $userId,
                'type' => $type,
                'category' => $type->category(),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject === null ? null : (string) $subject->getKey(),
                'data' => $data === [] ? null : $data,
                'link' => $link,
                'idempotency_key' => $idempotencyKey,
            ]));
        } catch (UniqueConstraintViolationException) {
            /*
             * Somebody already raised this exact notification — a retried job,
             * a redelivered webhook, two workers racing. Returning null rather
             * than the existing row is deliberate: the caller's only reasonable
             * use for a return value here is "did I just tell them", and the
             * answer is no.
             */
            return null;
        }

        foreach ($this->channelsFor($type, $userId) as $channel) {
            $delivery = NotificationDelivery::query()->create([
                'notification_id' => $notification->getKey(),
                'channel' => $channel,
                'status' => DeliveryStatus::Pending,
            ]);

            DeliverNotification::dispatch((string) $delivery->getKey());
        }

        return $notification;
    }

    /**
     * The channels this notification will actually go down.
     *
     * @return list<NotificationChannel>
     */
    private function channelsFor(NotificationType $type, ?string $userId): array
    {
        $category = $type->category();

        return array_values(array_filter(
            $type->defaultChannels(),
            function (NotificationChannel $channel) use ($category, $userId): bool {
                // An unimplemented channel is not silently skipped at delivery
                // time; it never gets a row, so nothing shows as pending for
                // ever waiting on a worker that does not exist.
                if (! $channel->isImplemented()) {
                    return false;
                }

                /*
                 * A category nobody may silence is not looked up at all. That
                 * is the enforcement: a preference row for "billing email off"
                 * can exist in the table — written by an older version, or by
                 * hand — and it will not be honoured.
                 */
                if (! in_array($channel, $category->disableableChannels(), strict: true)) {
                    return true;
                }

                if ($userId === null) {
                    return true;
                }

                $preference = NotificationPreference::query()
                    ->where('user_id', $userId)
                    ->where('category', $category->value)
                    ->where('channel', $channel->value)
                    ->first();

                // No row means enabled. A new category therefore works for
                // every existing account without a backfill.
                return $preference === null || $preference->enabled;
            },
        ));
    }
}
