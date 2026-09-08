<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Listeners;

use Lynomia\Modules\Billing\Domain\Enums\SettlementBasis;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
use Lynomia\Modules\Billing\Domain\Events\OrderFinanciallySettled;

/**
 * An order's invoice was paid, so the order owes nothing.
 *
 * A deliberately thin translation, and the reason it exists rather than having
 * fulfilment keep listening to InvoicePaid: delivery must depend on the
 * customer's obligation being discharged, not on a document existing. Two
 * situations discharge it — an invoice paid in full, and a total of zero — and
 * only one of them produces an invoice. With fulfilment listening to the
 * settlement instead, both reach the same place by the same route.
 *
 * A renewal invoice has a subscription and no order: its period was advanced
 * when the invoice was generated, there is nothing to fulfil, and no settlement
 * is announced.
 *
 * Not queued: it writes nothing and decides nothing. It restates one event as
 * another, and the listener that does the work is itself a job.
 */
final class AnnounceSettlementOnInvoicePaid
{
    public function handle(InvoicePaid $event): void
    {
        if ($event->orderId === null) {
            return;
        }

        event(new OrderFinanciallySettled(
            orderId: $event->orderId,
            customerId: $event->customerId,
            basis: SettlementBasis::InvoicePaid,
            invoiceId: $event->invoiceId,
            settledAt: $event->paidAt,
        ));
    }
}
