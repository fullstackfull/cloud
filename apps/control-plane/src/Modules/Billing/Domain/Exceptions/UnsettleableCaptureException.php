<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when a captured payment names rows that cannot be read.
 *
 * Money has already left the customer's account, so this is never a benign
 * "nothing to do": either the settlement is reading before the capture is
 * visible — which the retry fixes — or the rows really are gone, and a charge
 * with no invoice and no service needs a human. Both outcomes are better than
 * a log line the queue forgets.
 */
final class UnsettleableCaptureException extends DomainException
{
    public static function forCapture(string $transactionId, ?string $invoiceId): self
    {
        $exception = new self(sprintf(
            'Captured payment %s cannot be settled against invoice %s: one of the rows could not be read.',
            $transactionId,
            $invoiceId ?? 'unknown',
        ));

        return $exception->withContext([
            'transaction_id' => $transactionId,
            'invoice_id' => $invoiceId,
        ]);
    }

    public function errorCode(): string
    {
        return 'invoice.capture_not_readable';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
