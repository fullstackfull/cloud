<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Domain\Events;

use Carbon\CarbonImmutable;

/**
 * An invoice that was paid has had every unit of what it took returned.
 *
 * Raised only on the transition to refunded, never on a partial refund, and
 * once: RecordInvoiceRefund attaches each refund row to the invoice before it
 * books it, so a redelivered refund never reaches the transition a second
 * time. The mirror of InvoicePaid.
 *
 * The order behind the invoice is told, and records it (F-19): before this, a
 * purchase refunded in full still read `paid`. Recording is all it does — a
 * refund moves money, and what the customer bought is kept until somebody ends
 * it, which is a separate act.
 *
 * @immutable
 */
final readonly class InvoiceRefunded
{
    public function __construct(
        public string $invoiceId,
        public string $customerId,
        public ?string $orderId,
        public CarbonImmutable $refundedAt,
    ) {}
}
