<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Events;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Billing\Domain\Enums\SettlementBasis;

/**
 * Nothing further is owed on this order, so it may be delivered.
 *
 * This is the event fulfilment depends on, and the reason it exists is a defect
 * it replaces. Fulfilment used to hang off InvoicePaid, which ties delivery to
 * a document rather than to the customer's obligation — and an order whose
 * total is zero has no document, so it was placed as PAID and then nothing
 * happened to it, for ever. A customer redeeming a 100% coupon received a
 * confirmation and no service.
 *
 * Two things are deliberately *not* how this was fixed. There is no zero-value
 * transaction written to make the payment chain fire, and no synthetic provider
 * event: both would put a payment in the ledger that never happened, and an
 * accounting record that describes a fiction is worse than a missing feature.
 * The basis says which of the two real situations applies.
 *
 * @immutable
 */
final readonly class OrderFinanciallySettled
{
    public function __construct(
        public string $orderId,
        public string $customerId,
        public SettlementBasis $basis,
        public ?string $invoiceId,
        public CarbonImmutable $settledAt,
    ) {}
}
