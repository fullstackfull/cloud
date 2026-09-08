<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Application\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Support\Domain\Exceptions\TicketRefusedException;
use Lynomia\Modules\Support\Infrastructure\Models\SupportMessage;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;

/**
 * Adds a message to a ticket, and moves the ticket to whoever's turn it now is.
 *
 * **The status follows from who spoke.** A customer's reply makes it the
 * support team's turn; an operator's reply makes it the customer's. Nobody
 * chooses this, because a queue in which the state is set by hand is a queue
 * with tickets sitting in "waiting for customer" that the customer answered
 * last week.
 *
 * **An internal note moves nothing.** It is a message between colleagues; the
 * customer is still waiting for the same answer they were waiting for before
 * it was written, and a note that flipped the ticket to "waiting for customer"
 * would silently stop the clock on a reply nobody has sent.
 *
 * **A reply to a resolved ticket reopens it.** "That did not fix it" is the
 * most useful message a customer ever sends, and making them open a second
 * ticket throws away the history that explains the first. A closed ticket
 * refuses: closing is the account saying it is finished.
 */
final readonly class ReplyToTicket
{
    public function __construct(
        private StoreAttachments $attachments,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function execute(
        SupportTicket $ticket,
        User $author,
        string $body,
        MessageAuthorKind $kind,
        bool $isInternalNote = false,
        array $files = [],
    ): SupportMessage {
        $message = DB::transaction(function () use ($ticket, $author, $body, $kind, $isInternalNote): SupportMessage {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->acceptsReplies()) {
                throw TicketRefusedException::becauseItIsClosed((string) $locked->getKey());
            }

            /** @var SupportMessage $message */
            $message = SupportMessage::query()->create([
                'ticket_id' => $locked->getKey(),
                'author_user_id' => $author->getKey(),
                'author_kind' => $kind,
                'body' => $body,
                'is_internal_note' => $isInternalNote,
            ]);

            if (! $isInternalNote) {
                $locked->forceFill($this->stateAfter($locked, $kind))->save();
            }

            return $message;
        });

        if ($files !== []) {
            $this->attachments->execute($message, $files);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function stateAfter(SupportTicket $ticket, MessageAuthorKind $kind): array
    {
        $wasResolved = $ticket->status === TicketStatus::Resolved;

        $changes = [
            'status' => $kind === MessageAuthorKind::Customer
                ? TicketStatus::WaitingForSupport
                : TicketStatus::WaitingForCustomer,
            'last_reply_at' => now(),
            'last_reply_by' => $kind,
        ];

        if ($wasResolved) {
            /*
             * Reopened. The count is kept because a ticket resolved and
             * reopened four times is a ticket that was never actually fixed,
             * and that is worth being able to see without reading the thread.
             */
            $changes['reopened_count'] = $ticket->reopened_count + 1;
            $changes['resolved_at'] = null;
        }

        /*
         * The first time the support team says anything, and only the first.
         * An internal note never reaches here, so a team cannot post a note to
         * itself and record that as having answered the customer.
         */
        if ($kind === MessageAuthorKind::Operator && $ticket->first_responded_at === null) {
            $changes['first_responded_at'] = now();
        }

        return $changes;
    }
}
