<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

/**
 * The reset link an operator invitation sends, in the invitation's words.
 *
 * It used to be the framework's reset mail (QueuedResetPassword), which ends
 * "If you did not request a password reset, no further action is required".
 * For somebody whose customer login was just promoted that is wrong twice:
 * they requested nothing, and they cannot sign in again until they act — the
 * invitation has already taken the login's password, sessions, tokens and
 * second factor away (InviteOperator::takeAwayFromWhoeverHeldIt) (B8-3,
 * re-audit after round seven).
 *
 * **The same mail for every invitation.** It does not say whether the
 * address had a login: a new operator and a promoted customer get the same
 * subject and body, word for word, the second sentence saying what happened
 * to a login "if you already had one". An operator holding `role.manage` who
 * invites an address whose mailbox they can read learns nothing from it that
 * the invitation response does not tell them.
 *
 * In the platform's language (`app.locale`), as InvitationMailer does, and
 * for the same reason: the platform has no preference of the recipient's to
 * read — and a promoted login's history choosing the language would itself
 * say the login existed. The link and the token are the broker's, exactly as
 * a reset's: ResetPassword builds the URL (NotificationRoutingServiceProvider
 * points it at the SPA's reset form), and the token expires as any reset
 * token does (`auth.passwords.users.expire`).
 *
 * Queued, like the reset mail it replaces, so the invitation's response time
 * does not include an SMTP transaction.
 */
final class OperatorInvitation extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable): MailMessage
    {
        $language = (string) config('app.locale');
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject($this->line('invitations.operator.title', $language))
            ->markdown('notifications.message', [
                'title' => $this->line('invitations.operator.title', $language),
                'body' => $this->line('invitations.operator.body', $language, ['minutes' => (string) $minutes]),
                'url' => $this->resetUrl($notifiable),
                'rtl' => str_starts_with($language, 'ar'),
            ]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private function line(string $key, string $language, array $replace = []): string
    {
        $translated = Lang::get($key, $replace, $language);

        return is_string($translated) ? $translated : $key;
    }
}
