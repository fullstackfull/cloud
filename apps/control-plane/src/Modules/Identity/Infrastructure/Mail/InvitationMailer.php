<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Mail;

use Illuminate\Support\Facades\Mail;
use Lynomia\Modules\Identity\Application\DTOs\IssuedInvitation;
use Lynomia\Modules\Identity\Infrastructure\Models\User;

/**
 * Puts an invitation in the post.
 *
 * Separated from the actions that mint invitations for one reason: the mail is
 * the only thing in the flow that cannot be rolled back. An action that sent
 * inside its own transaction would post a link to an invitation that a later
 * failure erased, and the recipient would click into a 404 for an account they
 * were genuinely invited to. So the actions return the token and the caller
 * sends afterwards, once the row is committed.
 *
 * The queue does the rest: the mailable is `ShouldQueue`, so a slow or broken
 * SMTP server delays a message rather than failing the request that created
 * the invitation.
 */
final readonly class InvitationMailer
{
    public function send(IssuedInvitation $issued, ?User $inviter = null): void
    {
        $invitation = $issued->invitation;
        $account = (string) $invitation->customer->display_name;

        Mail::to($invitation->email)->send(new InvitationMail(
            accountName: $account,
            /*
             * The account's name when the invitation was made by nobody in
             * particular — an operator adopting an account, a console command.
             * "Somebody at Acme invited you" is true; a blank line where a
             * name should be is not.
             */
            inviterName: $inviter instanceof User ? $inviter->name : $account,
            acceptUrl: $this->urlFor($issued->token),
            /*
             * The account's language, not the invitee's: the platform has
             * never met the invitee and has no preference to read. A person
             * invited to an Arabic-speaking company is more likely to read
             * Arabic than the platform's default.
             */
            language: (string) config('app.locale'),
            expiresInDays: max(1, (int) config('teams.invitation_ttl_days', 14)),
        ));
    }

    /**
     * Absolute, and built from configuration rather than from a request: this
     * runs on a queue worker, which has no request to take an origin from.
     */
    private function urlFor(string $token): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $path = (string) config('teams.invitation_path', '/invitations/:token');

        return $base.str_replace(':token', $token, $path);
    }
}
