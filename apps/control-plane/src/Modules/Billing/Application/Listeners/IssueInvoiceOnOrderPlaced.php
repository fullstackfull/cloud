<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Listeners;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\IssueInvoiceForOrder;
use Lynomia\Modules\Orders\Domain\Events\OrderPlaced;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;

/**
 * Turns a placed order into something the customer can pay.
 *
 * This is the first link of the fulfilment chain, and it was missing: an order
 * placed through the API reached PENDING_PAYMENT and stopped there. Nothing
 * issued an invoice, so there was nothing to start a payment against, and the
 * rest of the chain — capture, settle, fulfil — could never begin. The
 * platform's own end-to-end test did not show it, because the test called
 * IssueInvoiceForOrder itself at the point where this listener belongs.
 *
 * **Not queued, deliberately.** Every other step of the chain reacts to money
 * that has already moved and can afford to be a job. This one is what the
 * customer is waiting for: they have just clicked buy, and the next thing they
 * do is pay. A queued listener would leave a placed order with no invoice for
 * as long as the queue is behind — and with no worker running at all, forever.
 * It is dispatched after the checkout transaction commits, so it never reads an
 * order that is not there.
 *
 * **Idempotent.** IssueInvoice locks the order and returns the existing invoice
 * rather than issuing a second one, so a redelivered event, a retried job or a
 * duplicate announcement all produce one invoice — and, importantly, do not
 * burn an invoice number.
 *
 * **A zero-total order gets no invoice.** An order fully covered by a discount
 * is placed as PAID, and an OPEN invoice for 0.000 would be a document with
 * nothing to pay that dunning would then chase. Such an order is also not
 * fulfilled by anything today, because fulfilment hangs off InvoicePaid and no
 * invoice is ever paid: that gap is recorded in docs/build-status.md rather
 * than papered over here with a settlement the Billing module does not model.
 */
final class IssueInvoiceOnOrderPlaced
{
    public function __construct(
        private readonly IssueInvoiceForOrder $issueInvoice,
    ) {}

    public function handle(OrderPlaced $event): void
    {
        if ($event->total->isZero()) {
            return;
        }

        $order = Order::query()->with(['items', 'customer'])->find($event->orderId);

        if ($order === null) {
            // The order committed before this fired, so its absence is data
            // loss rather than a race. Logged rather than thrown: the customer's
            // checkout has already succeeded, and there is nothing they can do
            // about it.
            Log::error('A placed order vanished before its invoice could be issued.', [
                'order_id' => $event->orderId,
                'customer_id' => $event->customerId,
            ]);

            return;
        }

        $this->issueInvoice->execute($order);
    }
}
