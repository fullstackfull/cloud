<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceSettlement;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
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
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;

/**
 * Applies a captured payment to an invoice.
 *
 * **How idempotency is achieved.** `amount_paid_minor` is not incremented, it
 * is re-derived: it is the sum of the captured charges attached to the invoice,
 * less anything an earlier overpayment sent to the wallet, capped at what the
 * document can still absorb. A redelivered webhook therefore settles once
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
 * **What the invoice can absorb.** The ceiling is the total plus whatever has
 * been refunded, not the total alone: a refund puts money back in the
 * customer's hands and makes it owed again, so a customer re-paying an invoice
 * they were refunded is settling it, not overpaying it.
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

            $this->assertSettleable($locked, $capture);

            $currency = $locked->currency;
            $paidBeforeMinor = $locked->amount_paid_minor;

            /*
             * Every captured charge already attached to this invoice, minus
             * the one in hand: adding it back below gives the same ledger
             * whether the payments side attached it before calling or leaves
             * the attaching to this action, which is what makes a redelivered
             * webhook and a pre-attached capture take the same path.
             */
            $otherChargesMinor = (int) Transaction::query()
                ->where('invoice_id', $locked->getKey())
                ->whereKeyNot($capture->getKey())
                ->where('kind', TransactionKind::Charge->value)
                ->where('status', TransactionStatus::Succeeded->value)
                ->sum('amount_minor');

            $ledgerMinor = $otherChargesMinor + $capture->amount_minor;

            /*
             * Captured money an earlier settlement moved to the wallet instead
             * of applying. It has to be subtracted from the ledger rather than
             * assumed away: a later refund raises what this invoice can absorb,
             * and without this the surplus that was already handed to the
             * customer as stored value would be counted a second time as
             * payment — the platform crediting a wallet and collecting the
             * same fils.
             */
            $divertedMinor = (int) WalletTransaction::query()
                ->where('invoice_id', $locked->getKey())
                ->where('kind', WalletTransactionKind::Topup->value)
                ->sum('amount_minor');

            $availableMinor = $ledgerMinor - $divertedMinor;

            /*
             * What the document can still absorb. Refunded money is owed again
             * — amount_due is total - paid + refunded — so the ceiling is the
             * total plus whatever went back. Measured against the total alone,
             * a customer re-paying an invoice they were refunded would look
             * like an overpayment: their money would go to the wallet and the
             * invoice would stay in dunning with nothing wrong with it.
             */
            $capacityMinor = $locked->total_minor + $locked->amount_refunded_minor;

            $appliedMinor = min($availableMinor, $capacityMinor);
            $surplusMinor = $availableMinor - $appliedMinor;

            /*
             * A capture that is already attached and changes nothing is a
             * redelivery, and a redelivery is a no-op whatever state the
             * document has since reached. Asking whether the invoice can take
             * a payment first would turn the routine second copy of a webhook
             * into an exception on every invoice refunded inside the
             * provider's retry window — a handler that fails forever over a
             * payment it has already recorded.
             */
            $isRedelivery = $capture->invoice_id === (string) $locked->getKey()
                && $appliedMinor === $paidBeforeMinor
                && $surplusMinor === 0;

            if (! $isRedelivery) {
                $this->assertPayable($locked);
            }

            if ($surplusMinor > 0 && ! $this->creditsOverpaymentToWallet()) {
                // Thrown before the attach and before any write, so a refused
                // overpayment leaves the invoice and the transaction untouched.
                throw InvoiceOverpaymentRefusedException::forInvoice(
                    (string) $locked->getKey(),
                    Money::ofMinor($capacityMinor - $paidBeforeMinor, $currency),
                    $capture->amount(),
                );
            }

            if ($capture->invoice_id === null) {
                // Attaching is what makes the recomputation above find this
                // payment next time, so it is the closest thing settlement has
                // to an "applied" marker.
                $capture->invoice_id = (string) $locked->getKey();
                $capture->save();
            }

            $locked->amount_paid_minor = $appliedMinor;
            $locked->save();

            // PostgreSQL recomputed amount_due_minor as part of that write.
            $locked->refresh();

            $becamePaid = false;

            if ($locked->amountDue()->isZero() && $locked->status !== InvoiceStatus::Paid) {
                $locked = $this->transitionInvoice->execute($locked, InvoiceStatus::Paid);
                $becamePaid = true;
            }

            $surplus = $this->creditSurplus($locked, $capture, $surplusMinor);

            /*
             * Announced only on the transition to paid, and only once — a
             * second capture against an already-paid invoice credits the wallet
             * but must not re-announce a fulfilment that has already happened.
             *
             * Dispatched after the commit so that a listener which starts a
             * subscription or queues provisioning cannot observe, or act on, an
             * invoice whose settlement is about to roll back.
             */
            if ($becamePaid) {
                $paidInvoice = $locked;

                DB::afterCommit(static function () use ($paidInvoice): void {
                    event(new InvoicePaid(
                        invoiceId: (string) $paidInvoice->getKey(),
                        customerId: (string) $paidInvoice->customer_id,
                        orderId: $paidInvoice->order_id,
                        subscriptionId: $paidInvoice->subscription_id,
                        paidAt: CarbonImmutable::instance($paidInvoice->paid_at ?? now()),
                    ));
                });
            }

            return new InvoiceSettlement(
                invoice: $locked,
                applied: Money::ofMinor($appliedMinor - $paidBeforeMinor, $currency),
                creditedToWallet: $surplus,
            );
        });
    }

    /**
     * Credits what this call could not apply to the customer's wallet.
     *
     * The figure handed in is already net of every earlier diversion, so a
     * second payment on an already-overpaid invoice credits only what it
     * added, and a redelivery of the first one credits nothing at all.
     */
    private function creditSurplus(Invoice $invoice, Transaction $capture, int $surplusMinor): Money
    {
        $currency = $invoice->currency;

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
