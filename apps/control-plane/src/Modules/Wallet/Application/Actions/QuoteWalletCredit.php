<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Application\Actions;

use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Application\DTOs\WalletCreditQuote;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;

/**
 * How much of an invoice stored credit would cover.
 *
 * Read-only, and deliberately does not open a wallet: asking what a payment
 * would do must not create a row. A customer with no wallet in the invoice's
 * currency gets a quote of zero rather than an empty wallet they never asked
 * for.
 *
 * The figure is the cached balance, which is the same column
 * {@see WalletLedger} locks and
 * enforces the debit against — never a fresh sum of the ledger, because two
 * ways of arriving at a balance is one way too many.
 *
 * A quote is not a promise. Between quoting and paying, a renewal can spend
 * the balance, so the payment recomputes everything under a lock and applies
 * what is actually there. This exists so a screen can show a number, not so a
 * payment can trust one.
 */
final readonly class QuoteWalletCredit
{
    public function execute(Customer $customer, Invoice $invoice): WalletCreditQuote
    {
        $currency = strtoupper($invoice->currency);
        $zero = Money::ofMinor(0, $currency);

        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()
            ->where('customer_id', $customer->getKey())
            ->where('currency', $currency)
            ->first();

        $available = $wallet === null ? $zero : Money::ofMinor($wallet->balance_minor, $currency);
        $due = $invoice->amountDue();

        $applicable = $available->isLessThan($due) ? $available : $due;

        return new WalletCreditQuote(
            available: $available,
            applicable: $applicable,
            remaining: $due->minus($applicable),
            // Nothing owed and no credit are different situations with the
            // same answer: there is no payment to make.
            isPayable: $applicable->isPositive(),
        );
    }
}
