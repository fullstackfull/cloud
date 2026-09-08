<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Infrastructure\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Lynomia\Modules\Notifications\Domain\ValueObjects\RenderedNotification;

/**
 * One notification as an email.
 *
 * Deliberately one mailable for every type rather than one class per message.
 * The difference between "your server is ready" and "your payment failed" is
 * entirely in the translated strings, and thirty near-identical Mailable
 * classes is thirty places for the layout to drift and one of them to leak
 * something the others do not.
 *
 * Nothing provider-specific reaches the template: it renders a title, a body
 * and at most a link into the customer's own portal.
 */
final class NotificationMail extends Mailable
{
    public function __construct(
        public readonly RenderedNotification $rendered,
        public readonly ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->rendered->title);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'notifications.message',
            with: [
                'title' => $this->rendered->title,
                'body' => $this->rendered->body,
                // Absolute, because an email client has no origin to resolve a
                // path against. Built from the configured portal URL rather
                // than from the request, which a queue worker does not have.
                'url' => $this->link === null
                    ? null
                    : rtrim((string) config('app.frontend_url', config('app.url')), '/').$this->link,
                'rtl' => str_starts_with($this->rendered->locale, 'ar'),
            ],
        );
    }
}
