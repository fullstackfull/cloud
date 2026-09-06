<?php

declare(strict_types=1);

namespace Lynomia\Modules\Wallet\Application\Actions;

use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Wallet\Domain\ValueObjects\WalletBalance;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use Lynomia\Modules\Wallet\Infrastructure\Queries\CustomerWallets;

/**
 * Every balance a customer holds, one per currency.
 *
 * ---------------------------------------------------------------------------
 * Why there is no single number
 * ---------------------------------------------------------------------------
 *
 * A customer transacting in two currencies holds two independent balances —
 * WalletLedger never converts, and the wallets table has a unique constraint
 * per (customer, currency) precisely so that a balance always has one currency
 * behind it. Adding 9.000 KWD to 30.00 USD to print a "total" would require a
 * rate, a rate would require a moment in time, and the answer would be wrong
 * for every purpose the customer might use it for. So this returns a list, and
 * the account currency is named alongside it for the common case.
 *
 * ---------------------------------------------------------------------------
 * Where the figure comes from
 * ---------------------------------------------------------------------------
 *
 * `wallets.balance_minor`, through Wallet::balance() — the same column
 * WalletLedger takes under a row lock and enforces a debit against. That is
 * what makes it the right one to show: a customer told they have 25.000 KWD
 * can spend 25.000 KWD, because the number on the screen and the number the
 * floor check reads are the same number.
 *
 * WalletLedger::recomputeBalance() deliberately is NOT used here. Summing the
 * ledger is a second way of arriving at a balance, and where the two disagree
 * the disagreement is a defect to be reported — that is what reconcile() and
 * WalletReconciliation are for — not something a customer endpoint should
 * quietly paper over by showing the figure nothing else in the platform obeys.
 */
final class GetCustomerWalletBalances
{
    /**
     * The customer's balances: the account currency first, then any other
     * currency they hold a wallet in, alphabetically.
     *
     * @return list<WalletBalance>
     */
    public function execute(Customer $customer): array
    {
        $accountCurrency = strtoupper($customer->currency);

        /** @var list<WalletBalance> $balances */
        $balances = CustomerWallets::of($customer)
            ->orderBy('currency')
            ->get()
            ->map(static fn (Wallet $wallet): WalletBalance => new WalletBalance(
                walletId: (string) $wallet->getKey(),
                balance: $wallet->balance(),
                // When the balance last moved. The ledger row carries the
                // narrative; this is just "is what I am looking at fresh".
                updatedAt: $wallet->updated_at,
            ))
            ->all();

        $hasAccountCurrency = false;

        foreach ($balances as $balance) {
            if ($balance->currency() === $accountCurrency) {
                $hasAccountCurrency = true;

                break;
            }
        }

        if (! $hasAccountCurrency) {
            // Reported, not opened. firstOrCreate here would let anyone with a
            // session write a wallets row on a GET, and would leave every
            // account that ever looked at its balance holding a wallet it
            // never used.
            $balances[] = WalletBalance::unopened($accountCurrency);
        }

        usort($balances, static function (WalletBalance $a, WalletBalance $b) use ($accountCurrency): int {
            if ($a->currency() === $accountCurrency) {
                return $b->currency() === $accountCurrency ? 0 : -1;
            }

            if ($b->currency() === $accountCurrency) {
                return 1;
            }

            return strcmp($a->currency(), $b->currency());
        });

        return array_values($balances);
    }
}
