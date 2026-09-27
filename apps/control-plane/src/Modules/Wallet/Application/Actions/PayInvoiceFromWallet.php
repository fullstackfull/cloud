<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceSettlement;
use Lynomia\Modules\Billing\Application\Queries\TheSubscriptionAnInvoiceBills;
use Lynomia\Modules\Billing\Application\Queries\WhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceNotPayableException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Queries\PlanChangeDelivery;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Exceptions\IdempotencyKeyConflictException;
use Lynomia\Modules\Wallet\Domain\Exceptions\WalletPaymentRefusedException;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;

/**
 * Spends stored credit against an invoice.
 *
 * ---------------------------------------------------------------------------
 * Why this is not a special case inside checkout
 * ---------------------------------------------------------------------------
 *
 * Settlement already has exactly one way of learning that an invoice was paid:
 * a `transactions` row, kind charge, status succeeded, attached to the
 * invoice. {@see SettleInvoice} says so in its own docblock — "a wallet-funded
 * or manually recorded settlement has to write one too, or the recomputation
 * will not see it" — and the whole idempotency story rests on it, because the
 * amount paid is re-derived from those rows rather than incremented.
 *
 * So a wallet payment is a payment like any other. It writes the same row with
 * provider `wallet`, and hands it to the same settlement action. Nothing about
 * invoices, dunning, overpayment or refunds needed a second code path, and
 * there is no arithmetic here that could disagree with the arithmetic there.
 *
 * ---------------------------------------------------------------------------
 * What holds the money still
 * ---------------------------------------------------------------------------
 *
 * One database transaction, and inside it, in this order:
 *
 *  1. **The invoice, locked.** Everything the amount depends on — the status,
 *     the total, what has already been paid — is re-read from the committed
 *     row rather than from the instance the caller passed in, which may have
 *     been loaded before a webhook settled the document.
 *  2. **The wallet, locked** by WalletLedger, which refuses a debit that would
 *     take the balance below zero *after* taking the lock. Two payments racing
 *     for the same 30 KWD therefore serialise: the second sees the first one's
 *     balance and applies what is actually left.
 *  3. **The charge row, then settlement**, which locks the charge and the
 *     invoice again — both already held by this transaction, so no new wait.
 *
 * The money-path lock order ({@see WhatAnInvoiceStillHolds}) puts an existing
 * capture before its invoice. The charge locked here after the invoice is a
 * row this transaction created, which no other transaction can be holding or
 * waiting for, so it cannot close a cycle. A replay never locks the charge the
 * first request wrote (see execute()).
 *
 * ---------------------------------------------------------------------------
 * Repeating the request
 * ---------------------------------------------------------------------------
 *
 * The idempotency key is the caller's, and it goes on the *ledger entry*
 * rather than on a table of its own. A repeat therefore returns the original
 * debit from WalletLedger without posting a second one, and settlement — which
 * re-derives from the transactions attached to the invoice — recomputes the
 * same figure. Two clicks spend one balance once.
 *
 * It goes there namespaced ({@see self::ledgerKey()}: `wallet-pay:<customer>:<key>`),
 * never raw. The ledger's key space is shared with the platform's own
 * postings, whose keys are predictable (`invoice:<id>:lapsed-upgrade:<minor>`,
 * `invoice:<id>:cancelled-order:<minor>`, `invoice:<id>:subscription-ended:…`,
 * `invoice:<id>:withdrawn-refund-failed:…`), and a customer who paid under
 * exactly such a key made the platform's later credit a conflicting "replay"
 * of their debit: every renewal of the subscription threw and the service ran
 * on unbilled (OX-1, round four's re-audit). No system key starts with the
 * prefix, and the request rule cannot produce one that does not.
 *
 * ---------------------------------------------------------------------------
 * Currencies
 * ---------------------------------------------------------------------------
 *
 * Never converted. A customer holding KWD credit against a USD invoice is not
 * short of money; the platform simply has no rate it would honour, and
 * inventing one settles an invoice at a number nobody agreed.
 */
final readonly class PayInvoiceFromWallet
{
    public function __construct(
        private WalletLedger $ledger,
        private SettleInvoice $settle,
        private PlanChangeDelivery $planChanges,
    ) {}

    /**
     * @throws WalletPaymentRefusedException
     * @throws InvoiceNotPayableException
     */
    public function execute(Customer $customer, Invoice $invoice, string $idempotencyKey): InvoiceSettlement
    {
        $ledgerKey = self::ledgerKey($customer, $idempotencyKey);

        return DB::transaction(function () use ($customer, $invoice, $idempotencyKey, $ledgerKey): InvoiceSettlement {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            $wallet = $this->walletIfAny($customer, $locked->currency);

            /*
             * The replay question is asked before any other, and that ordering
             * is the whole of idempotency here.
             *
             * A repeat of a request that succeeded arrives at an invoice the
             * first one settled — which is no longer collectible. Checking
             * payability first would answer the second copy of a successful
             * request with `invoice.not_payable`, so a client that lost the
             * first response and retried would be told its payment failed
             * when it had in fact worked. The invoice lock above is what makes
             * the question safe to ask: two copies serialise on it.
             */
            if ($wallet !== null) {
                $replay = $this->ledger->entryPostedUnder($wallet, $ledgerKey)
                    ?? $this->paymentPostedUnderTheRawKey($wallet, $idempotencyKey, (string) $locked->getKey());

                if ($replay !== null && (string) $replay->invoice_id !== (string) $locked->getKey()) {
                    // The same key on a different invoice is not a repeat of
                    // the same request (R4): refused, as the ledger refuses a
                    // key reused for a different entry.
                    throw IdempotencyKeyConflictException::forAnotherInvoice(
                        (string) $wallet->getKey(),
                        $idempotencyKey,
                        (string) $replay->getKey(),
                        (string) $locked->getKey(),
                    );
                }

                if ($replay !== null) {
                    /*
                     * The first request wrote the charge, the debit and the
                     * settlement in one transaction, so finding its entry
                     * means all three are committed: what settlement would
                     * answer now is a redelivery, which moves nothing. Said
                     * here rather than asked of SettleInvoice, because that
                     * locks the charge, and a charge locked after its invoice
                     * is the opposite of the money-path lock order
                     * (WhatAnInvoiceStillHolds) - a replay racing a refund of
                     * the same wallet charge would deadlock.
                     */
                    return new InvoiceSettlement(
                        invoice: $locked->refresh(),
                        applied: Money::zero($locked->currency),
                        creditedToWallet: Money::zero($locked->currency),
                    );
                }
            }

            if (! $locked->status->isCollectible()) {
                throw InvoiceNotPayableException::forStatus((string) $locked->getKey(), $locked->status);
            }

            // Nothing more is delivered on a subscription that has ended, so
            // an invoice of it left open is not paid for nothing (O-1, N-3).
            if (TheSubscriptionAnInvoiceBills::hasEnded($locked)) {
                throw InvoiceNotPayableException::becauseItsSubscriptionHasEnded((string) $locked->getKey());
            }

            // Nor is a plan change that can no longer be delivered paid for
            // from the wallet: the question the card payment asks
            // (StartInvoicePayment), F-07.
            $refused = $this->planChanges->refusalForTheInvoice($locked);

            if ($refused !== null) {
                Log::warning('A wallet payment was refused because the plan change its invoice bills can no longer be delivered.', [
                    'invoice_id' => (string) $locked->getKey(),
                    'customer_id' => (string) $locked->customer_id,
                    'reason' => $refused,
                ]);

                throw InvoiceNotPayableException::becauseItsPlanChangeCannotBeDelivered((string) $locked->getKey());
            }

            $due = $locked->amountDue();

            if (! $due->isPositive()) {
                throw WalletPaymentRefusedException::becauseNothingIsOwed((string) $locked->getKey());
            }

            if ($wallet === null) {
                /*
                 * No wallet in this currency at all. Opening one in order to
                 * refuse the debit would leave a row the customer never asked
                 * for, and the refusal names the currency because a customer
                 * with a balance in another one is looking at a screen that
                 * says they have credit.
                 */
                throw WalletPaymentRefusedException::becauseTheBalanceIsEmpty(strtoupper($locked->currency));
            }

            $available = $this->ledger->balance($wallet);

            if (! $available->isPositive()) {
                throw WalletPaymentRefusedException::becauseTheBalanceIsEmpty($wallet->currency);
            }

            /*
             * Partial payment is the ordinary case, not an error. A customer
             * with 30 KWD of credit against a 100 KWD invoice pays 30 from the
             * wallet and 70 by card, and refusing that would make credit
             * spendable only on invoices it happens to cover exactly.
             */
            $applied = $available->isLessThan($due) ? $available : $due;

            /*
             * The charge is written before the debit, so that a debit which
             * refuses — the balance moved between the read and the lock — rolls
             * back a charge that was never attached to anything. The reverse
             * order would leave the money spent for an instant with nothing
             * recording where it went, and a crash in that instant is a
             * customer's credit disappearing.
             */
            $charge = $this->newCharge($locked, $applied, $customer);

            $entry = $this->ledger->debit(
                wallet: $wallet,
                amount: $applied,
                kind: WalletTransactionKind::Payment,
                description: sprintf('Applied to invoice %s', $locked->number),
                metadata: ['invoice_number' => $locked->number],
                idempotencyKey: $ledgerKey,
                invoiceId: (string) $locked->getKey(),
                // The two rows point at each other, so a statement line can be
                // traced to the invoice it paid and back again.
                transactionId: (string) $charge->getKey(),
            );

            /*
             * The ledger entry is this charge's "provider reference".
             *
             * It is what makes the charge refundable: IssueRefund refuses a
             * capture with no reference, on the sound principle that money it
             * cannot name at the provider is money it cannot ask for back. For
             * a wallet payment the provider is the platform itself and the
             * reference is the statement line, so the refund knows exactly
             * which debit it is reversing.
             */
            $charge->forceFill(['provider_reference' => (string) $entry->getKey()])->save();

            return $this->settle->execute($locked, $charge);
        });
    }

    /**
     * The ledger key a customer's Idempotency-Key is posted under: in a
     * namespace of its own, per customer, so it can never be one of the
     * platform's keys (see the class docblock, OX-1).
     */
    public static function ledgerKey(Customer $customer, string $idempotencyKey): string
    {
        return sprintf('wallet-pay:%s:%s', $customer->getKey(), $idempotencyKey);
    }

    /**
     * A payment of this invoice posted under the customer's raw key, before
     * keys were namespaced: a retry of it is the replay it always was.
     *
     * Only a wallet payment of this same invoice counts. Anything else under
     * the raw key - a platform posting whose key the customer's happens to
     * match, or a payment of another invoice - is not this request's, and is
     * not looked at: that is the collision the namespace exists to end (OX-1).
     */
    private function paymentPostedUnderTheRawKey(Wallet $wallet, string $idempotencyKey, string $invoiceId): ?WalletTransaction
    {
        $entry = $this->ledger->entryPostedUnder($wallet, $idempotencyKey);

        if ($entry === null
            || $entry->kind !== WalletTransactionKind::Payment
            || (string) $entry->invoice_id !== $invoiceId) {
            return null;
        }

        return $entry;
    }

    /**
     * The customer's wallet in this currency, or null. Deliberately does not
     * open one: a request that will be refused must not leave a row behind.
     */
    private function walletIfAny(Customer $customer, string $currency): ?Wallet
    {
        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()
            ->where('customer_id', $customer->getKey())
            ->where('currency', strtoupper($currency))
            ->first();

        return $wallet;
    }

    private function newCharge(Invoice $invoice, Money $applied, Customer $customer): Transaction
    {
        /** @var Transaction $charge */
        $charge = Transaction::query()->create([
            'customer_id' => $customer->getKey(),
            // Attached by SettleInvoice rather than here: that is settlement's
            // own applied marker, and setting it early would make an unsettled
            // charge look applied to the guard in StartInvoicePayment.
            'invoice_id' => null,
            'provider' => 'wallet',
            'kind' => TransactionKind::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => $applied->minorUnits(),
            'currency' => $applied->currency(),
            'processed_at' => now(),
        ]);

        return $charge;
    }
}
