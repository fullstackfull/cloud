<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Application\Actions;

use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Support\Infrastructure\Models\SupportMessage;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;

/**
 * Tells the customer what has happened on their ticket.
 *
 * **Only ever the customer.** An operator learns about a ticket from the
 * queue, not from a notification addressed to an account they do not belong
 * to; and a customer replying to their own ticket must not be told that they
 * replied.
 *
 * **Deduplicated by the thing that happened.** The idempotency key names the
 * message or the transition rather than the moment, so a retried job or a
 * double-submitted reply produces one notification. `NotifyCustomer` answers
 * null when the key has been seen, which is the whole mechanism.
 *
 * **Never carries the message body.** A notification is a pointer to the
 * ticket, not a copy of it: bodies contain whatever a customer or an operator
 * wrote, including — on a billing ticket — things that should not be sitting
 * in an inbox that may be read on a shared screen. The subject is the
 * customer's own words and is safe to echo, once flattened to one line.
 */
final readonly class NotifyAboutTicket
{
    public function __construct(
        private NotifyCustomer $notify,
    ) {}

    public function opened(SupportTicket $ticket): void
    {
        $this->send($ticket, NotificationType::TicketOpened, 'opened:'.$ticket->getKey());
    }

    public function repliedByOperator(SupportTicket $ticket, SupportMessage $message): void
    {
        // An internal note is a message between colleagues. Telling the
        // customer about it would be both a wrong notification and a
        // disclosure that a private conversation is happening.
        if ($message->is_internal_note) {
            return;
        }

        $this->send($ticket, NotificationType::TicketReplied, 'replied:'.$message->getKey());
    }

    public function resolved(SupportTicket $ticket): void
    {
        $this->send(
            $ticket,
            NotificationType::TicketResolved,
            // Includes the resolution time, so a ticket resolved, reopened and
            // resolved again notifies twice — which is correct, and would not
            // happen with a key naming only the ticket.
            'resolved:'.$ticket->getKey().':'.$ticket->resolved_at?->getTimestamp(),
        );
    }

    public function closed(SupportTicket $ticket): void
    {
        $this->send($ticket, NotificationType::TicketClosed, 'closed:'.$ticket->getKey());
    }

    private function send(SupportTicket $ticket, NotificationType $type, string $key): void
    {
        $this->notify->execute(
            customerId: (string) $ticket->customer_id,
            type: $type,
            idempotencyKey: 'ticket:'.$key,
            subject: $ticket,
            data: [
                'reference' => $ticket->reference,
                'subject' => $ticket->subject,
            ],
            link: '/support/'.$ticket->getKey(),
            /*
             * Addressed to the person who opened it rather than to the account
             * at large: on a business account the billing mailbox is often a
             * finance team who did not ask the question. Null when that login
             * is gone, which falls back to the account's own address.
             */
            userId: $ticket->opened_by_user_id,
        );
    }
}
