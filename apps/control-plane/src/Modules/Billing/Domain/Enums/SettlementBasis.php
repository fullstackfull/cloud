<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Enums;

/**
 * Why an order counts as financially settled.
 *
 * Recorded rather than inferred, because the two answers are audited
 * differently: one has a payment behind it that can be refunded, and the other
 * has nothing to refund because nothing was ever collected.
 */
enum SettlementBasis: string
{
    /** An invoice was issued and paid in full. */
    case InvoicePaid = 'invoice_paid';

    /**
     * The order's total was zero, so no money was ever owed.
     *
     * Not "paid". Nothing was paid, no document was issued, and no transaction
     * exists; the platform simply has nothing left to collect before it can
     * deliver.
     */
    case NoPaymentRequired = 'no_payment_required';
}
