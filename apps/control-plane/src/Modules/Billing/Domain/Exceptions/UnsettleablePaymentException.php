<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when a transaction cannot be the payment for an invoice.
 *
 * Settlement reads the transaction ledger to decide how much an invoice has
 * been paid, so a row that is not a captured charge belonging to the same
 * customer must never be attached to it: the arithmetic would be right and the
 * answer would be wrong.
 */
final class UnsettleablePaymentException extends DomainException
{
    public static function notACapture(string $transactionId, string $kind, string $status): self
    {
        $exception = new self(sprintf(
            'Transaction %s is a %s in state "%s" and is not a captured charge.',
            $transactionId,
            $kind,
            $status,
        ));

        return $exception->withContext([
            'transaction_id' => $transactionId,
            'kind' => $kind,
            'status' => $status,
        ]);
    }

    public static function belongsToAnotherCustomer(string $transactionId, string $invoiceId): self
    {
        $exception = new self(sprintf(
            'Transaction %s belongs to a different customer than invoice %s.',
            $transactionId,
            $invoiceId,
        ));

        return $exception->withContext(['transaction_id' => $transactionId, 'invoice_id' => $invoiceId]);
    }

    public static function alreadyAppliedElsewhere(string $transactionId, string $attachedInvoiceId, string $invoiceId): self
    {
        $exception = new self(sprintf(
            'Transaction %s already settles invoice %s and cannot also settle %s.',
            $transactionId,
            $attachedInvoiceId,
            $invoiceId,
        ));

        return $exception->withContext([
            'transaction_id' => $transactionId,
            'attached_invoice_id' => $attachedInvoiceId,
            'invoice_id' => $invoiceId,
        ]);
    }

    public static function refundAttachedElsewhere(string $refundId, string $attachedInvoiceId, string $invoiceId): self
    {
        $exception = new self(sprintf(
            'Refund %s is already recorded against invoice %s and cannot also reduce %s.',
            $refundId,
            $attachedInvoiceId,
            $invoiceId,
        ));

        return $exception->withContext([
            'refund_id' => $refundId,
            'attached_invoice_id' => $attachedInvoiceId,
            'invoice_id' => $invoiceId,
        ]);
    }

    public function errorCode(): string
    {
        return 'invoice.payment_not_settleable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
