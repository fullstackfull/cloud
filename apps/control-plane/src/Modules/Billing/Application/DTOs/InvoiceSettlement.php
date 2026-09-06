<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\DTOs;

use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * What settling one payment against one invoice actually moved.
 *
 * Both figures are deltas for this call, not running totals, which is what
 * makes a redelivered webhook legible: applying the same transaction a second
 * time returns the settled invoice with `applied` and `creditedToWallet` at
 * zero, so a caller can tell "this payment paid the invoice" from "this
 * payment had already paid the invoice" without comparing snapshots.
 *
 * @immutable
 */
final readonly class InvoiceSettlement
{
    public function __construct(
        public Invoice $invoice,
        /** Added to the invoice's paid amount by this call. */
        public Money $applied,
        /** Surplus credited to the customer's wallet by this call. */
        public Money $creditedToWallet,
    ) {}

    public function movedNothing(): bool
    {
        return $this->applied->isZero() && $this->creditedToWallet->isZero();
    }
}
