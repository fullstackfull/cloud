<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;

/**
 * Puts a refund on the document it came off.
 *
 * IssueRefund sends the money back and announces RefundIssued. Nothing listened,
 * and RecordInvoiceRefund — the action that writes the refund onto the invoice —
 * had no caller at all. So an operator could refund a customer in full and the
 * invoice would still read as paid, in full, with 0.000 refunded: the platform's
 * books said it had kept money it had already returned, the customer's copy of
 * their own invoice said the same, and `amount_due`, which PostgreSQL derives
 * from those figures, agreed with both.
 *
 * It also mattered beyond the books. SettleInvoice sizes what an invoice may
 * still accept as `total + amount_refunded`, so a refunded invoice that was
 * never told about its refund would refuse a re-payment the customer is
 * entitled to make.
 *
 * **Queued, unlike the invoice that follows a placed order.** The money has
 * already left by the time this runs. A synchronous listener that threw would
 * fail the operator's request after the provider had paid out, and the natural
 * response to a failed refund request is to issue it again — which is the one
 * mistake this must not invite. As a job it retries on its own, and a failure
 * that survives every attempt lands in failed_jobs where it can be replayed
 * without touching the provider.
 *
 * Idempotent through RecordInvoiceRefund, which attaches the refund row to the
 * invoice and reduces nothing a second time if it is already attached.
 */
final class RecordRefundAgainstTheInvoice implements ShouldQueue
{
    public string $queue = 'payments';

    public int $tries = 5;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 60, 300];
    }

    public function __construct(
        private readonly RecordInvoiceRefund $record,
    ) {}

    public function handle(RefundIssued $event): void
    {
        if ($event->invoiceId === null) {
            // A refund of a payment that was never attached to an invoice —
            // an over-collection returned, say. There is no document to record
            // it on; the transaction and the refund row carry the history.
            return;
        }

        $invoice = Invoice::query()->find($event->invoiceId);

        if ($invoice === null) {
            Log::error('A refund names an invoice that no longer exists.', [
                'refund_id' => $event->refundId,
                'invoice_id' => $event->invoiceId,
                'customer_id' => $event->customerId,
            ]);

            return;
        }

        $this->record->execute($invoice, $event->amount, Refund::query()->find($event->refundId));
    }
}
