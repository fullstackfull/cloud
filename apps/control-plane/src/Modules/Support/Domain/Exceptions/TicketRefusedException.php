<?php

declare(strict_types=1);

namespace Lynomia\Modules\Support\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Something the support module will not do.
 */
final class TicketRefusedException extends DomainException
{
    private string $errorCode = 'support.refused';

    private int $status = 409;

    public static function becauseItIsClosed(string $ticketId): self
    {
        return (new self('This ticket is closed. Open a new one and link to this reference.'))
            ->withContext(['ticket_id' => $ticketId])
            ->as('support.ticket_closed');
    }

    public static function becauseItIsAlreadyOpen(string $ticketId): self
    {
        return (new self('This ticket is already open.'))
            ->withContext(['ticket_id' => $ticketId])
            ->as('support.ticket_already_open');
    }

    public static function becauseTheAccountHasTooManyOpenTickets(int $ceiling): self
    {
        return (new self('This account has too many open tickets. Reply on an existing one instead.'))
            ->withContext(['limit' => $ceiling])
            ->as('support.too_many_open_tickets');
    }

    public static function becauseTheFileIsTooLarge(int $limit): self
    {
        return (new self('That file is larger than attachments may be.'))
            ->withContext(['max_bytes' => $limit])
            ->as('support.attachment_too_large')
            ->withStatus(422);
    }

    /**
     * The type is named in the refusal and the file's claimed name is not:
     * echoing a customer-supplied filename into an error message is how a
     * refusal becomes a reflection point.
     */
    public static function becauseTheTypeIsNotAllowed(string $detected): self
    {
        return (new self('That kind of file cannot be attached.'))
            ->withContext(['detected_type' => $detected])
            ->as('support.attachment_type_not_allowed')
            ->withStatus(422);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }

    private function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }
}
