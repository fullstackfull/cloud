<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceSettlement;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceNotPayableException;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceOverpaymentRefusedException;
use Lynomia\Modules\Billing\Domain\Exceptions\UnsettleablePaymentException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;

/**
 * Applies a captured payment to an invoice.
 *
 * **How idempotency is achieved.** `amount_paid_minor` is not incremented, it
 * is re-derived: it is the sum of the captured charges attached to the invoice,
 * capped at the invoice total. A redelivered webhook therefore settles once
 * because the same transaction row is summed once, not because a flag was
 * checked — and two partial payments accumulate for exactly the same reason.
 * The invariant this rests on is that every payment applied to an invoice is a
 * transactions row: a wallet-funded or manually recorded settlement has to
 * write one too (provider "wallet"/"manual", kind charge, status succeeded),
 * or the recomputation will not see it.
 *
 * **Why the surplus goes to the wallet.** A customer who pays more than they
 * owe has the difference credited to their wallet rather than refused: the
 * money has already left their account and been captured by the provider, so
 * refusing it would leave a captured payment attached to nothing, and sending
 * it back costs days and a provider fee for what is usually a rounding or a
 * double-click. Where an operator wants the refusal instead,
 * `billing.credit_overpayment_to_wallet` turns it off and this action throws
 * before writing anything.
 *
 * The whole thing runs in one transaction with the invoice row locked, because
 * two payments landing at once would otherwise both read the same paid figure
 * and one of them would disappear.
 */
final readonly class SettleInvoice
{
    /**
     * The statuses that can still take money.
     *
     * Paid is included on purpose: a duplicate capture against an invoice that
     * is already settled is real money, and it belongs in the wallet rather
     * than nowhere.
     */
    private const array PAYABLE = [
        InvoiceStatus::Open,
        InvoiceStatus::Uncollectible,
        InvoiceStatus::Paid,
    ];

    public function __construct(
        private TransitionInvoice $transitionInvoice,
        private WalletLedger $wallet,
    ) {}

    /**
     * @throws InvoiceNotPayableException
     * @throws InvoiceOverpaymentRefusedException
     * @throws UnsettleablePaymentException
     * @throws CurrencyMismatchException
     */
    public function execute(Invoice $invoice, Transaction $payment): InvoiceSettlement
    {
        return DB::transaction(function () use ($invoice, $payment): InvoiceSettlement {
            /*
             * Lock ordering convention: the payment-side row first, then the
             * invoice. The payments flow records a capture and then asks for it
             * to be settled, so it already holds the transaction when it gets
             * here; taking the two rows in the other order would let the two
             * paths deadlock against each other under load.
             */
            /** @var Transaction $capture */
            $capture = Transaction::query()->lockForUpdate()->findOrFail($payment->getKey());
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            $this->assertPayable($locked);
            $this->assertSettleable($locked, $capture);

            $currency = $locked->currency;
            $totalMinor = $locked->total_minor;
            $paidBeforeMinor = $locked->amount_paid_minor;

            /*
             * Everything already attached to this invoice, deliberately
             * excluding the capture in hand: the two sums are what tell this
             * payment's own contribution apart from the running total, so a
             * replay computes the same surplus it computed the first time
             * instead of crediting it again on top.
             */
            $priorMinor = (int) Transaction::query()
                ->where('invoice_id', $locked->getKey())
                ->whereKeyNot($capture->getKey())
                ->where('kind', TransactionKind::Charge->value)
                ->where('status', TransactionStatus::Succeeded->value)
                ->sum('amount_minor');

            $ledgerMinor = $priorMinor + $capture->amount_minor;

            if ($ledgerMinor > $totalMinor && ! $this->creditsOverpaymentToWallet()) {
                // Thrown before the attach and before any write, so a refused
                // overpayment leaves the invoice and the transaction untouched.
                throw InvoiceOverpaymentRefusedException::forInvoice(
                    (string) $locked->getKey(),
                    Money::ofMinor($totalMinor - $paidBeforeMinor, $currency),
                    $capture->amount(),
                );
            }

            if ($capture->invoice_id === null) {
                // Attaching is what makes the recomputation above find this
                // payment next time, so it is the closest thing settlement has
                // to a "applied" marker.
                $capture->invoice_id = (string) $locked->getKey();
                $capture->save();
            }

            $appliedMinor = min($ledgerMinor, $totalMinor);
            $locked->amount_paid_minor = $appliedMinor;
            $locked->save();

            // PostgreSQL recomputed amount_due_minor as part of that write.
            $locked->refresh();

            if ($locked->amountDue()->isZero() && $locked->status !== InvoiceStatus::Paid) {
                $locked = $this->transitionInvoice->execute($locked, InvoiceStatus::Paid);
            }

            $surplus = $this->creditSurplus($locked, $capture, $priorMinor, $ledgerMinor, $totalMinor);

            return new InvoiceSettlement(
                invoice: $locked,
                applied: Money::ofMinor($appliedMinor - $paidBeforeMinor, $currency),
                creditedToWallet: $surplus,
            );
        });
    }

    /**
     * Credits this payment's share of the overpayment to the customer's wallet.
     *
     * The share is the difference between the surplus before this payment and
     * the surplus after it, so a third payment on an already-overpaid invoice
     * credits only what it added rather than the whole overhang a second time.
     */
    private function creditSurplus(
        Invoice $invoice,
        Transaction $capture,
        int $priorMinor,
        int $ledgerMinor,
        int $totalMinor,
    ): Money {
        $currency = $invoice->currency;
        $surplusMinor = max(0, $ledgerMinor - $totalMinor) - max(0, $priorMinor - $totalMinor);

        if ($surplusMinor <= 0) {
            return Money::zero($currency);
        }

        $surplus = Money::ofMinor($surplusMinor, $currency);

        /** @var Customer $customer */
        $customer = $invoice->customer()->firstOrFail();

        $entry = $this->wallet->credit(
            wallet: $this->wallet->walletFor($customer, $currency),
            amount: $surplus,
            // Money the customer handed over that no invoice claims is stored
            // value, which is what a top-up is; calling it a payment would
            // read as the wallet having settled something.
            kind: WalletTransactionKind::Topup,
            description: sprintf('Overpayment of invoice %s', $invoice->number),
            idempotencyKey: sprintf('invoice:%s:overpayment:%s', $invoice->getKey(), $capture->getKey()),
            invoiceId: (string) $invoice->getKey(),
            transactionId: (string) $capture->getKey(),
        );

        // The ledger returns the original entry for a replayed key without
        // crediting again; reporting the amount either way would tell the
        // caller money moved when it did not.
        return $entry->wasRecentlyCreated ? $surplus : Money::zero($currency);
    }

    private function assertPayable(Invoice $invoice): void
    {
        if (! in_array($invoice->status, self::PAYABLE, strict: true)) {
            throw InvoiceNotPayableException::forStatus((string) $invoice->getKey(), $invoice->status);
        }
    }

    private function assertSettleable(Invoice $invoice, Transaction $capture): void
    {
        if ($capture->currency !== $invoice->currency) {
            throw CurrencyMismatchException::between($invoice->currency, $capture->currency);
        }

        if ($capture->customer_id !== $invoice->customer_id) {
            throw UnsettleablePaymentException::belongsToAnotherCustomer(
                (string) $capture->getKey(),
                (string) $invoice->getKey(),
            );
        }

        if (! $capture->isCaptured() || ! $capture->amount()->isPositive()) {
            throw UnsettleablePaymentException::notACapture(
                (string) $capture->getKey(),
                $capture->kind->value,
                $capture->status->value,
            );
        }

        if ($capture->invoice_id !== null && $capture->invoice_id !== $invoice->getKey()) {
            // One capture cannot pay two invoices: whichever recomputation ran
            // second would count money the first invoice is already counting.
            throw UnsettleablePaymentException::alreadyAppliedElsewhere(
                (string) $capture->getKey(),
                (string) $capture->invoice_id,
                (string) $invoice->getKey(),
            );
        }
    }

    private function creditsOverpaymentToWallet(): bool
    {
        return (bool) config('billing.credit_overpayment_to_wallet', true);
    }
}
