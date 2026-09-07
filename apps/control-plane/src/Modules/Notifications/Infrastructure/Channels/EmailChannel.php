<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Infrastructure\Channels;

use Illuminate\Support\Facades\Mail;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Application\Actions\RenderNotification;
use Lynomia\Modules\Notifications\Domain\Contracts\NotificationDeliveryChannel;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Notifications\Infrastructure\Mail\NotificationMail;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;

/**
 * Email, in the recipient's own language.
 *
 * ---------------------------------------------------------------------------
 * Who it goes to
 * ---------------------------------------------------------------------------
 *
 * The user who owns the notification when there is one; otherwise the
 * customer's billing address. That order matters: a notification raised by
 * somebody's own action — they changed their password, they asked for a
 * restore — belongs in their inbox, not the account's billing mailbox, which
 * on a business account is often a finance team who did not do it.
 *
 * ---------------------------------------------------------------------------
 * Sent synchronously from inside a queued job
 * ---------------------------------------------------------------------------
 *
 * `Mail::send`, not `Mail::queue`. This already runs on the notifications
 * queue, and queueing from here would put the message on a second queue where
 * a failure could not be written back to the delivery row — the record would
 * say "sent" for a message that was merely handed to another queue, which is
 * the sort of half-truth this table exists to avoid.
 */
final readonly class EmailChannel implements NotificationDeliveryChannel
{
    public function __construct(
        private RenderNotification $renderer,
    ) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function canReach(Notification $notification): bool
    {
        return $this->addressFor($notification) !== null;
    }

    public function deliver(Notification $notification): string
    {
        $address = $this->addressFor($notification);

        if ($address === null) {
            // canReach() is checked first by the job; reaching here means the
            // address disappeared between the two, which is a real failure and
            // not something to paper over with a default recipient.
            throw new \RuntimeException('The recipient has no email address.');
        }

        $rendered = $this->renderer->execute($notification, $this->localeFor($notification));

        Mail::to($address)->send(new NotificationMail($rendered, $notification->link));

        return $address;
    }

    private function addressFor(Notification $notification): ?string
    {
        $user = $notification->user_id === null
            ? null
            : User::query()->find($notification->user_id);

        if ($user !== null && $user->email !== '') {
            return $user->email;
        }

        $billing = $notification->customer()->first()?->billing_email;

        return is_string($billing) && $billing !== '' ? $billing : null;
    }

    /**
     * The recipient's language, not the platform's.
     *
     * A queue worker has no request behind it, so the application locale is
     * whatever the process was configured with — English. Sending an Arabic
     * customer English mail because a worker had no request is exactly the
     * failure the stored-facts design exists to prevent.
     */
    private function localeFor(Notification $notification): string
    {
        $user = $notification->user_id === null
            ? null
            : User::query()->find($notification->user_id);

        $locale = $user?->locale;

        return is_string($locale) && $locale !== '' ? $locale : (string) config('app.locale', 'en');
    }
}
