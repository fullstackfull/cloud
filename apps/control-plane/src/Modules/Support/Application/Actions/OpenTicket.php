<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Application\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Support\Domain\Enums\MessageAuthorKind;
use Lynomia\Modules\Support\Domain\Enums\TicketCategory;
use Lynomia\Modules\Support\Domain\Enums\TicketPriority;
use Lynomia\Modules\Support\Domain\Enums\TicketStatus;
use Lynomia\Modules\Support\Domain\Exceptions\TicketRefusedException;
use Lynomia\Modules\Support\Infrastructure\Models\SupportMessage;
use Lynomia\Modules\Support\Infrastructure\Models\SupportTicket;

/**
 * Opens a ticket, with its first message.
 *
 * A ticket and its opening message are written together or not at all. A
 * ticket with no message is a row in the queue that says nothing, and an
 * operator opening it learns only that somebody wanted something.
 *
 * **Attachments are stored after the transaction commits**, and the reason is
 * the same one that keeps the invitation mail outside its transaction: a file
 * written to disk cannot be rolled back, so a failure after the write would
 * leave an orphan on a disk nothing references. Doing it the other way leaves
 * a message referencing a file that was never written, which is worse — a
 * customer told their log was attached when it was not.
 */
final readonly class OpenTicket
{
    public function __construct(
        private StoreAttachments $attachments,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function execute(
        Customer $customer,
        User $author,
        string $subject,
        string $body,
        TicketCategory $category,
        TicketPriority $priority,
        ?string $serviceId = null,
        ?string $invoiceId = null,
        array $files = [],
    ): SupportTicket {
        $this->assertThereIsRoomForAnother($customer);

        /** @var array{0: SupportTicket, 1: SupportMessage} $created */
        $created = DB::transaction(function () use (
            $customer, $author, $subject, $body, $category, $priority, $serviceId, $invoiceId
        ): array {
            /** @var SupportTicket $ticket */
            $ticket = SupportTicket::query()->create([
                'customer_id' => $customer->getKey(),
                'opened_by_user_id' => $author->getKey(),
                'reference' => $this->nextReference(),
                'subject' => $this->oneLine($subject),
                'category' => $category,
                'status' => TicketStatus::Open,
                'priority' => $priority,
                'service_id' => $serviceId,
                'invoice_id' => $invoiceId,
                'last_reply_at' => now(),
                'last_reply_by' => MessageAuthorKind::Customer,
            ]);

            /** @var SupportMessage $message */
            $message = SupportMessage::query()->create([
                'ticket_id' => $ticket->getKey(),
                'author_user_id' => $author->getKey(),
                'author_kind' => MessageAuthorKind::Customer,
                'body' => $body,
                'is_internal_note' => false,
            ]);

            return [$ticket, $message];
        });

        [$ticket, $message] = $created;

        if ($files !== []) {
            $this->attachments->execute($message, $files);
        }

        return $ticket;
    }

    /**
     * A short human reference, unique, and not guessable in sequence.
     *
     * Sequential numbering would let anybody who opened one ticket know how
     * many the platform has ever had, and would make a neighbouring reference
     * a thing worth guessing at. The date gives an operator the ordering they
     * actually want; the random tail is what stops the enumeration.
     */
    private function nextReference(): string
    {
        do {
            $reference = sprintf('LYN-%s-%s', now()->format('ym'), mb_strtoupper(bin2hex(random_bytes(3))));
        } while (SupportTicket::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * A subject is a single line. It is rendered into notification titles and
     * mail subjects, and a newline in a subject is a header somebody chose.
     */
    private function oneLine(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, 200);
    }

    /**
     * A bound on open tickets per account.
     *
     * Not a commercial limit: an account that can open tickets as fast as it
     * can POST can bury a support queue, and every ticket in it is a
     * notification somebody has to read. Replying on an existing ticket is
     * always allowed, so nobody is stopped from asking for help.
     */
    private function assertThereIsRoomForAnother(Customer $customer): void
    {
        $ceiling = max(1, (int) config('support.max_open_tickets_per_customer', 20));

        $open = SupportTicket::query()->where('customer_id', $customer->getKey())->live()->count();

        if ($open >= $ceiling) {
            throw TicketRefusedException::becauseTheAccountHasTooManyOpenTickets($ceiling);
        }
    }
}
