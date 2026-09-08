<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Lang;

/**
 * The one message that goes to somebody who may not be a customer at all.
 *
 * Every other message this platform sends belongs to a notification row, which
 * belongs to a customer, which has users with preferences and languages. An
 * invitation has none of that: the recipient is an address, and until they
 * accept there is nothing to attach a preference to. So it is a plain queued
 * mailable rather than a notification, and it is not switchable off — there is
 * nobody yet to hold the switch.
 *
 * It reuses the notification layout so that the first mail a person receives
 * from Lynomia looks like the ones that follow.
 *
 * **What it does not say.** Not who else is in the account, not what the
 * account owns, not whether the address already has a login. The subject names
 * the account, because a person who was expecting an invitation needs to
 * recognise it and a person who was not needs to know who to complain about.
 */
final class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $accountName,
        public readonly string $inviterName,
        public readonly string $acceptUrl,
        /*
         * Named `language` rather than `locale`: Mailable already declares a
         * non-readonly `$locale` of its own, and a readonly redeclaration of
         * an inherited property is a fatal error at class-load time — which
         * shows up as a dead PHP process rather than as an exception.
         */
        public readonly string $language,
        public readonly int $expiresInDays,
    ) {
        $this->onQueue('notifications');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->line('invitations.invited.title'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'notifications.message',
            with: [
                'title' => $this->line('invitations.invited.title'),
                'body' => $this->line('invitations.invited.body'),
                'url' => $this->acceptUrl,
                'rtl' => str_starts_with($this->language, 'ar'),
            ],
        );
    }

    /**
     * Every value that reaches the template is flattened to one line first.
     *
     * The account name is chosen by a customer and the inviter's name by a
     * person, and both land in a subject header. A newline in a subject is a
     * header, and a header somebody else chose is a recipient somebody else
     * chose. This is the same rule `RenderNotification` applies, applied at
     * the one place that does not go through it.
     */
    private function line(string $key): string
    {
        $replacements = [
            'account' => $this->oneLine($this->accountName),
            'inviter' => $this->oneLine($this->inviterName),
            'days' => (string) $this->expiresInDays,
        ];

        $translated = Lang::get($key, $replacements, $this->language);

        return is_string($translated) ? $translated : $key;
    }

    private function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }
}
