<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Queries\WhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;

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
        private WalletLedger $wallet,
    ) {}

    /**
     * @return int the minor units newly credited by this call — zero on a retry
     */
    public function execute(string $orderId): int
    {
        $credited = 0;

        $ids = Invoice::query()->where('order_id', $orderId)->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $credited += DB::transaction(fn (): int => $this->creditTheRemainderOf((string) $id));
        }

        return $credited;
    }

    private function creditTheRemainderOf(string $invoiceId): int
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

        $capturedMinor = WhatAnInvoiceStillHolds::capturedMinor($invoice);
        $alreadyInTheWalletMinor = WhatAnInvoiceStillHolds::creditedToTheWalletMinor($invoice);
        $refundedMinor = WhatAnInvoiceStillHolds::refundedMinor($invoice);

        $remainderMinor = $capturedMinor - $alreadyInTheWalletMinor - $refundedMinor;

        if ($remainderMinor <= 0) {
            return 0;
        }

        /** @var Customer $customer */
        $customer = $invoice->customer()->firstOrFail();

        $this->wallet->credit(
            wallet: $this->wallet->walletFor($customer, $invoice->currency),
            amount: Money::ofMinor($remainderMinor, $invoice->currency),
            // Stored value the customer handed over that no delivery claims:
            // the same kind, and so the same place in SettleInvoice's
            // arithmetic, as an overpayment surplus.
            kind: WalletTransactionKind::Topup,
            description: sprintf('Payment for invoice %s returned: the order was cancelled', $invoice->number),
            metadata: [
                'invoice_id' => (string) $invoice->getKey(),
                'order_id' => $invoice->order_id,
                'captured_minor' => $capturedMinor,
                'already_in_wallet_minor' => $alreadyInTheWalletMinor,
                'refunded_minor' => $refundedMinor,
            ],
            idempotencyKey: sprintf(
                'invoice:%s:cancelled-order:%d',
                $invoice->getKey(),
                $capturedMinor - $refundedMinor,
            ),
            invoiceId: (string) $invoice->getKey(),
        );

        /*
         * The remainder is what this call moved. A second run under the same
         * lock finds it already in the wallet and returns zero above; the
         * idempotency key is a backstop for the ledger, not the thing that
         * makes a retry credit nothing.
         */
        return $remainderMinor;
    }
}
