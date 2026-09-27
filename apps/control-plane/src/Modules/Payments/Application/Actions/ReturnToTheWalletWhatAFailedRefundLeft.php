<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\ReturnWhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;

/**
 * A refund that failed, against an invoice that was withdrawn while it was in
 * flight: what the invoice holds again goes to the wallet.
 *
 * An invoice withdrawn because nothing more will be delivered for it (an
 * upgrade that lapsed, the open invoice of a subscription that ended) has
 * what it still holds returned to the wallet and is voided
 * (ReturnWhatAnInvoiceStillHolds). A refund pending at that moment is counted
 * as money already going back, so the withdrawal leaves that part to it. If
 * the refund then fails, its reservation is released and the void invoice
 * holds that money again - with nothing left to deliver it and nothing to
 * return it. So when a refund is recorded as failed or cancelled and its
 * invoice is void, this hands what the invoice now holds to the wallet,
 * through the same action, recorded against the invoice as before. Under the
 * invoice's lock and idempotent: a second call finds nothing held.
 *
 * Called by IssueRefund (a provider that refuses at once, or throws) and by
 * SettleRefundFromProvider (a pending refund the provider later fails, and -
 * returnWhatAReversedRefundLeft() - a succeeded one it later reverses).
 */
final readonly class ReturnToTheWalletWhatAFailedRefundLeft
{
    public function __construct(
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
    ) {}

    public function returnWhatAVoidInvoiceHolds(Refund $refund, Transaction $capture): int
    {
        return $this->returnIfItsInvoiceIs(
            $refund,
            $capture,
            [InvoiceStatus::Void],
            'withdrawn-refund-failed',
            'Payment for invoice %s returned: it was withdrawn, and a refund of it failed',
        );
    }

    /**
     * A refund the provider reversed after reporting it succeeded
     * (SettleRefundFromProvider, OA-4), once it is un-booked from its invoice.
     *
     * The invoice holds that money again. A void invoice delivers nothing more,
     * as above; a refunded one is terminal (InvoiceStateMachine: nothing
     * reopens it) and its money was being returned to the customer when the
     * provider put it back - so for both it goes to the wallet, recorded
     * against the invoice. An invoice that still stands is left as it is,
     * refundable again.
     */
    public function returnWhatAReversedRefundLeft(Refund $refund, Transaction $capture): int
    {
        return $this->returnIfItsInvoiceIs(
            $refund,
            $capture,
            [InvoiceStatus::Void, InvoiceStatus::Refunded],
            'refund-reversed',
            'Payment for invoice %s returned: a refund of it was reversed by the payment provider',
        );
    }

    /**
     * @param  list<InvoiceStatus>  $statuses
     */
    private function returnIfItsInvoiceIs(Refund $refund, Transaction $capture, array $statuses, string $purpose, string $description): int
    {
        $invoiceId = $refund->invoice_id ?? $capture->invoice_id;

        if ($invoiceId === null) {
            return 0;
        }

        return DB::transaction(function () use ($invoiceId, $statuses, $purpose, $description): int {
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->find($invoiceId);

            if ($invoice === null || ! in_array($invoice->status, $statuses, true)) {
                return 0;
            }

            return $this->returnWhatItHolds->toTheWallet(
                $invoice,
                $purpose,
                sprintf($description, $invoice->number),
            );
        });
    }
}
