<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Raised when an invoice that has taken money is voided.
 *
 * Voiding says the document should never have existed. Once the customer has
 * paid against it that is no longer true, and the money would be left with
 * nothing to belong to: the way out of a paid invoice is a refund, which moves
 * the money back and leaves both sides of the story on the record.
 */
final class PaidInvoiceCannotBeVoidedException extends DomainException
{
    public static function forInvoice(string $invoiceId, Money $paid): self
    {
        $exception = new self(sprintf(
            'Invoice %s has been paid %s and can only be refunded, not voided.',
            $invoiceId,
            $paid,
        ));

        return $exception->withContext([
            'invoice_id' => $invoiceId,
            'amount_paid_minor' => $paid->minorUnits(),
            'currency' => $paid->currency(),
        ]);
    }

    public function errorCode(): string
    {
        return 'invoice.paid_cannot_be_voided';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
