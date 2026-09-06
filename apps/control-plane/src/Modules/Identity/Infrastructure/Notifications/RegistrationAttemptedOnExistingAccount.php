<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the owner of an address that somebody just tried to register with.
 *
 * The registration endpoint answers identically whether or not the address is
 * already taken, because a differing answer is a membership oracle. That
 * silence is deliberate towards the requester, but it must not extend to the
 * person who actually owns the account: they are the one party entitled to
 * know, and for them the message is genuinely useful - either they forgot they
 * had signed up, in which case the reset link is what they wanted, or somebody
 * else is probing their address, which is worth knowing early.
 *
 * Queued, so that the registration endpoint costs the same wall-clock time for
 * a known address as for an unknown one. A synchronous send would restore
 * through timing exactly the oracle the constant response closes.
 */
final class RegistrationAttemptedOnExistingAccount extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Someone tried to create an account with your email address')
            ->line('An account already exists for this address, so nothing was created.')
            ->line('If that was you, you may have been trying to sign in.')
            ->action('Reset your password', rtrim((string) config('app.frontend_url'), '/').'/forgot-password')
            ->line('If it was not you, no action is needed: whoever it was learned nothing about your account.');
    }
}
