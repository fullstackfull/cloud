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
 * SettleRefundFromProvider (a pending refund the provider later fails).
 */
final readonly class ReturnToTheWalletWhatAFailedRefundLeft
{
    public function __construct(
        private ReturnWhatAnInvoiceStillHolds $returnWhatItHolds,
    ) {}

    public function returnWhatAVoidInvoiceHolds(Refund $refund, Transaction $capture): int
    {
        $invoiceId = $refund->invoice_id ?? $capture->invoice_id;

        if ($invoiceId === null) {
            return 0;
        }

        return DB::transaction(function () use ($invoiceId): int {
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->find($invoiceId);

            if ($invoice === null || $invoice->status !== InvoiceStatus::Void) {
                return 0;
            }

            return $this->returnWhatItHolds->toTheWallet(
                $invoice,
                'withdrawn-refund-failed',
                sprintf('Payment for invoice %s returned: it was withdrawn, and a refund of it failed', $invoice->number),
            );
        });
    }
}
