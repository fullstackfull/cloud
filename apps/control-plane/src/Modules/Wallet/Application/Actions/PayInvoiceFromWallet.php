<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Application\Actions;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\DTOs\InvoiceSettlement;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceNotPayableException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Exceptions\WalletPaymentRefusedException;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;

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
 * The lock order is invoice → wallet → charge, and the external payments path
 * is charge → invoice → wallet. The charge here is a row this transaction
 * created, which no other transaction can be holding, so the two orders cannot
 * form a cycle.
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
    ) {}

    /**
     * @throws WalletPaymentRefusedException
     * @throws InvoiceNotPayableException
     */
    public function execute(Customer $customer, Invoice $invoice, string $idempotencyKey): InvoiceSettlement
    {
        return DB::transaction(function () use ($customer, $invoice, $idempotencyKey): InvoiceSettlement {
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
                $replay = $this->ledger->entryPostedUnder($wallet, $idempotencyKey);

                if ($replay !== null) {
                    return $this->settle->execute($locked, $this->chargeOf($replay->transaction_id));
                }
            }

            if (! $locked->status->isCollectible()) {
                throw InvoiceNotPayableException::forStatus((string) $locked->getKey(), $locked->status);
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
                idempotencyKey: $idempotencyKey,
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

    /** The charge an earlier attempt already wrote. */
    private function chargeOf(?string $id): Transaction
    {
        /** @var Transaction $charge */
        $charge = Transaction::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $charge;
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
