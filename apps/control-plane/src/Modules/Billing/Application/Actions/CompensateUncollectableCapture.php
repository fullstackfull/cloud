<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Queries\WhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;

/**
 * Money that arrived for a document which can no longer take it.
 *
 * ---------------------------------------------------------------------------
 * The race this exists for
 * ---------------------------------------------------------------------------
 *
 * A customer opens the payment page, changes their mind, and cancels. The
 * order is withdrawn and its invoice is voided, so nothing further can be
 * collected — but an intent already exists at the provider, and the provider
 * may still report a genuine capture seconds later. The money is real and it
 * is theirs.
 *
 * Refusing the capture at settlement is not an answer. The provider has the
 * money either way; all a refusal achieves is a failed job and a charge
 * attached to nothing, which is the shape of the defect rather than its fix.
 *
 * ---------------------------------------------------------------------------
 * Why the wallet, and not a refund
 * ---------------------------------------------------------------------------
 *
 * Because this domain has already answered the same question once.
 * {@see SettleInvoice} credits an overpayment to the wallet rather than
 * sending it back, and says why: the money has already left the customer's
 * account and been captured, so refusing leaves a payment attached to nothing,
 * and returning it costs days and a provider fee. A capture against a voided
 * invoice is the same fact in a starker form — the whole amount is surplus.
 *
 * {@see IssueRefund} is the
 * other half of that separation and is deliberately not used here: it reverses
 * one named capture through the provider, it is an operator's decision with
 * its own authorisation, and nothing automatic should be paying money out. A
 * customer who wants it back asks, and an operator issues it from the balance
 * this leaves them.
 *
 * Keyed on the capture, so a redelivered webhook credits once. The ledger
 * refuses a second entry under a key it has already posted.
 *
 * ---------------------------------------------------------------------------
 * No more than the invoice still holds of it
 * ---------------------------------------------------------------------------
 *
 * A capture RecordPaymentCapture attached to the invoice is already part of
 * what that invoice holds (WhatAnInvoiceStillHolds), and an invoice withdrawn
 * because nothing more will be delivered for it has had that figure returned
 * to the wallet first (ReturnWhatAnInvoiceStillHolds) - capture included. So
 * for an attached capture this credits what the invoice still holds, up to the
 * capture, read under the invoice's lock: crediting the capture in full again
 * would hand the same money back twice. A capture not attached to the invoice
 * is in nobody's figure, and is credited whole, as before.
 */
final readonly class CompensateUncollectableCapture
{
    public function __construct(
        private WalletLedger $wallet,
    ) {}

    public function execute(Invoice $invoice, Transaction $capture): ?WalletTransaction
    {
        return DB::transaction(fn (): ?WalletTransaction => $this->compensate($invoice, $capture));
    }

    private function compensate(Invoice $invoice, Transaction $capture): ?WalletTransaction
    {
        // The capture, then the invoice: the money-path lock order.
        /** @var Transaction $capture */
        $capture = Transaction::query()->lockForUpdate()->findOrFail($capture->getKey());
        /** @var Invoice $invoice */
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

        $amount = $capture->amount();

        // Counted in the invoice's figure only when it is one of the captures
        // that figure reads: attached, in its currency, from its customer.
        if ((string) $capture->invoice_id === (string) $invoice->getKey()
            && $capture->currency === $invoice->currency
            && (string) $capture->customer_id === (string) $invoice->customer_id) {
            $held = Money::ofMinor(max(0, WhatAnInvoiceStillHolds::minor($invoice)), $amount->currency());
            $amount = $held->isLessThan($amount) ? $held : $amount;
        }

        if (! $amount->isPositive()) {
            return null;
        }

        /** @var Customer $customer */
        $customer = $capture->customer()->firstOrFail();

        return $this->wallet->credit(
            wallet: $this->wallet->walletFor($customer, $amount->currency()),
            amount: $amount,
            /*
             * Stored value, which is what this is: money the customer handed
             * over that no document claims. The same kind SettleInvoice uses
             * for a surplus, and for the same stated reason — calling it a
             * payment would read as the wallet having settled something.
             */
            kind: WalletTransactionKind::Topup,
            description: sprintf('Payment received after invoice %s was withdrawn', $invoice->number),
            metadata: [
                'invoice_id' => (string) $invoice->getKey(),
                'invoice_status' => $invoice->status->value,
                'order_id' => $invoice->order_id,
            ],
            idempotencyKey: sprintf('capture:%s:uncollectable', $capture->getKey()),
            invoiceId: (string) $invoice->getKey(),
            transactionId: (string) $capture->getKey(),
        );
    }
}
