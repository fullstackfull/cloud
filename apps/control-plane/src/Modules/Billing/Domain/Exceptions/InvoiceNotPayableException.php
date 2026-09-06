<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Exceptions;

use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Raised when money is applied to an invoice that cannot receive it.
 *
 * A draft has not been issued, so nothing is owed yet; a void invoice was
 * withdrawn and a refunded one has already been unwound. Money that arrives
 * against any of them is a mis-attributed payment, and silently absorbing it
 * would hide that.
 */
final class InvoiceNotPayableException extends DomainException
{
    public static function forStatus(string $invoiceId, InvoiceStatus $status): self
    {
        $exception = new self(sprintf(
            'Invoice %s is %s and cannot receive a payment.',
            $invoiceId,
            $status->value,
        ));

        return $exception->withContext(['invoice_id' => $invoiceId, 'status' => $status->value]);
    }

    public function errorCode(): string
    {
        return 'invoice.not_payable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
