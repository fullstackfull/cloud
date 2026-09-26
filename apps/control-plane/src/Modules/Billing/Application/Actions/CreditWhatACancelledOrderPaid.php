<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;

/**
 * Credits the customer with what they paid for an order that was cancelled
 * and that nobody has given back yet (F-05).
 *
 * CancelOrder refuses an order whose invoice has taken money, under the
 * invoice's lock, so a cancelled order beside a paid invoice should not be
 * written any more. Rows written before that refusal existed can still be
 * there, and fulfilment finding one used to throw on every retry until the job
 * landed in failed_jobs, with the money kept and nothing delivered. This is
 * what fulfilment does with such an order instead.
 *
 * ---------------------------------------------------------------------------
 * The net, not the captures
 * ---------------------------------------------------------------------------
 *
 * For each of the order's invoices, what is still held for the customer is:
 *
 *     the captured charges applied to it
 *   − what has already gone to the wallet against it (a top-up carrying the
 *     invoice's id: SettleInvoice's overpayment surplus, a compensation for a
 *     late capture, or an earlier run of this action)
 *   − what has been refunded on it, whichever channel the refund went back
 *     through, including a card refund still pending at the provider
 *
 * and only that is credited. The figure is WhatAnInvoiceStillHolds, which
 * IssueRefund and RecordInvoiceRefund read too: money credited here is then
 * refused to a later card refund of the same capture, so the two orders — a
 * refund then this credit, or this credit then a refund — agree. The first version credited every capture in
 * full, and the verifier measured both ways that was wrong: a 2.000 capture
 * on a 1.500 invoice, whose 0.500 surplus SettleInvoice had already sent to
 * the wallet, credited 2.000 more (2.500 back for 2.000 paid); and a paid
 * invoice refunded by card before the retried job ran got the money back a
 * second time in the wallet.
 *
 * Computed under the invoice's row lock, which is the lock SettleInvoice and
 * the refund recorder take, so a retried job or a concurrent run waits for
 * the first, then counts its credit among what is already in the wallet and
 * finds nothing left. The entry is also posted under a key naming the invoice
 * and the figure it credits up to, which the ledger will not post twice.
 *
 * The wallet and not the card, for the reason CompensateUncollectableCapture
 * gives: nothing automatic pays money out; a customer who wants it back on the
 * card asks, and an operator issues it from the balance. The invoice is left
 * as it is — it was paid, and that is still true.
 */
final readonly class CreditWhatACancelledOrderPaid
{
    public function __construct(
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
    ) {}

    /**
     * @return int the minor units newly credited by this call — zero on a retry
     */
    public function execute(string $orderId): int
    {
        $credited = 0;

        $ids = Invoice::query()->where('order_id', $orderId)->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $credited += $this->creditTheRemainderOf((string) $id);
        }

        return $credited;
    }

    /**
     * Through ReturnWhatAnInvoiceStillHolds, the one implementation of "give
     * back what this invoice still holds" (it reads WhatAnInvoiceStillHolds
     * under the invoice's lock). The remainder is what this call moved; a
     * second run under the same lock finds it already in the wallet and
     * returns zero; the idempotency key is a backstop for the ledger, not the
     * thing that makes a retry credit nothing.
     */
    private function creditTheRemainderOf(string $invoiceId): int
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->findOrFail($invoiceId);

        return $this->returnWhatItHolds->toTheWallet(
            $invoice,
            'cancelled-order',
            sprintf('Payment for invoice %s returned: the order was cancelled', $invoice->number),
            ['order_id' => $invoice->order_id],
        );
    }
}
