<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Raised when a payment exceeds what an invoice is owed and the platform is
 * configured not to keep the surplus.
 *
 * The default policy credits the surplus to the customer's wallet — see
 * SettleInvoice — so this only fires where an operator has deliberately turned
 * that off, and it fires before anything is written.
 */
final class InvoiceOverpaymentRefusedException extends DomainException
{
    public static function forInvoice(string $invoiceId, Money $due, Money $offered): self
    {
        $exception = new self(sprintf(
            'Invoice %s is owed %s but %s was offered, and surplus is not credited to the wallet.',
            $invoiceId,
            $due,
            $offered,
        ));

        return $exception->withContext([
            'invoice_id' => $invoiceId,
            'due_minor' => $due->minorUnits(),
            'offered_minor' => $offered->minorUnits(),
            'currency' => $due->currency(),
        ]);
    }

    public function errorCode(): string
    {
        return 'invoice.overpayment_refused';
    }
}
