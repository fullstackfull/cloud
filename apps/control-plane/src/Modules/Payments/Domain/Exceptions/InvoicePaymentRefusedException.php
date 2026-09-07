<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The platform declined to start a payment for an invoice.
 *
 * Distinct from InvoiceNotPayableException, which answers "is this document in
 * a state that can receive money at all?" from the invoice's own status. These
 * two refusals are about the *money already in flight* against a document that
 * is still open:
 *
 *  - nothing is owed, so an intent would collect zero;
 *  - a capture has already been recorded and is on its way to settlement, so a
 *    second intent would collect the same invoice twice. That window is real:
 *    the ledger records a capture the moment the webhook lands, and the
 *    invoice is settled by a listener afterwards. A customer refreshing the
 *    pay page during those milliseconds must be refused, not charged again.
 *
 * Both are 409 rather than 422: the request was well formed and would have
 * been accepted a moment earlier or later.
 */
final class InvoicePaymentRefusedException extends DomainException
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public static function nothingIsOwed(string $invoiceId): self
    {
        $exception = new self(
            sprintf('Invoice %s has nothing left to pay.', $invoiceId),
            'payment.nothing_to_pay',
        );

        return $exception->withContext(['invoice_id' => $invoiceId]);
    }

    public static function aCaptureIsAlreadyRecorded(string $invoiceId, string $transactionId): self
    {
        $exception = new self(
            sprintf(
                'A payment for invoice %s has already been captured and is being settled.',
                $invoiceId,
            ),
            'payment.already_captured',
        );

        return $exception->withContext([
            'invoice_id' => $invoiceId,
            'transaction_id' => $transactionId,
        ]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
