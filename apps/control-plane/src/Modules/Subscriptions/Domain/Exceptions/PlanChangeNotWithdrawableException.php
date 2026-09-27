<?php

declare(strict_types=1);

namespace Lynomia\Modules\Subscriptions\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The invoice named does not bill an unpaid plan change the customer can
 * withdraw: it bills something else (a renewal, an order), it is no longer
 * open, or the subscription has moved on from the change it bills - ended,
 * or changed again since - so withdrawing it would not put the plan back.
 */
final class PlanChangeNotWithdrawableException extends DomainException
{
    public static function forInvoice(string $invoiceId): self
    {
        return (new self(sprintf('Invoice %s does not bill an unpaid plan change that can be withdrawn.', $invoiceId)))
            ->withContext(['invoice_id' => $invoiceId]);
    }

    public function errorCode(): string
    {
        return 'invoice.plan_change_not_withdrawable';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
