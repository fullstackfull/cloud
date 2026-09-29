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
    private string $errorCode = 'invoice.not_payable';

    /**
     * The invoice is open, but the subscription it bills has ended, so
     * nothing more will be delivered for it (O-1, N-3). Its open invoices are
     * withdrawn when it ends (WindUpAnEndedSubscription); this refuses a
     * payment for one that could not be.
     */
    public static function becauseItsSubscriptionHasEnded(string $invoiceId): self
    {
        $exception = new self(sprintf(
            'Invoice %s bills a subscription that has ended and cannot receive a payment.',
            $invoiceId,
        ));
        $exception->errorCode = 'invoice.subscription_ended';

        return $exception->withContext(['invoice_id' => $invoiceId]);
    }

    /**
     * The invoice bills a plan change that can no longer be delivered - the
     * package behind a hosting plan was withdrawn after the change was
     * accepted, say - so taking the money would buy nothing (F-07, asked
     * again at payment as an order is). The customer is told no more than
     * that; the reason is logged for an operator.
     */
    public static function becauseItsPlanChangeCannotBeDelivered(string $invoiceId): self
    {
        $exception = new self(sprintf(
            'Invoice %s bills a plan change that cannot be delivered now and cannot receive a payment.',
            $invoiceId,
        ));
        $exception->errorCode = 'invoice.plan_change_not_deliverable';

        return $exception->withContext(['invoice_id' => $invoiceId]);
    }

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
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
