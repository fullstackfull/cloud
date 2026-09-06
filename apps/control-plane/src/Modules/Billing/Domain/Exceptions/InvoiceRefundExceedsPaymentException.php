<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Raised when more would be refunded against an invoice than it ever received.
 *
 * The check is against what remains refundable rather than the invoice total,
 * so a sequence of partial refunds cannot add up to more than the customer
 * actually paid.
 */
final class InvoiceRefundExceedsPaymentException extends DomainException
{
    public static function forInvoice(string $invoiceId, Money $refundable, Money $requested): self
    {
        $exception = new self(sprintf(
            'Invoice %s has %s left to refund but %s was requested.',
            $invoiceId,
            $refundable,
            $requested,
        ));

        return $exception->withContext([
            'invoice_id' => $invoiceId,
            'refundable_minor' => $refundable->minorUnits(),
            'requested_minor' => $requested->minorUnits(),
            'currency' => $refundable->currency(),
        ]);
    }

    public function errorCode(): string
    {
        return 'invoice.refund_exceeds_payment';
    }
}
