<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;

/**
 * Checks that every wallet's cached balance still matches its own ledger.
 *
 * The cached column exists because a customer's balance is read on every page
 * that mentions money and summing a ledger per read does not scale. A cache
 * that can drift needs something that notices when it has — and until now
 * nothing did: WalletLedger could reconcile and re-derive, and no code path
 * ever asked it to.
 *
 * Two independent things are checked, because two different faults produce
 * them. The cached balance can disagree with the sum of the entries, which
 * means a write happened outside the ledger or one was lost. And an individual
 * entry's running total can disagree with the entries before it, which catches
 * a ledger tampered with in the middle where the endpoints still add up.
 *
 * Nothing is corrected. A wallet holds a customer's money, and a command that
 * quietly rewrote a balance to match whichever number it preferred would
 * destroy the evidence of how the two came apart. What it does is say so,
 * loudly, with the wallet named.
 */
final class VerifyWalletLedgers extends Command
{
    protected $signature = 'wallet:verify {--limit=1000 : How many wallets to check in one pass}';

    protected $description = 'Compare every wallet\'s cached balance against its own ledger and report any drift.';

    public function handle(WalletLedger $ledger): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $checked = 0;
        $drifted = 0;

        Wallet::query()
            ->orderBy('id')
            ->limit($limit)
            ->each(function (Wallet $wallet) use ($ledger, &$checked, &$drifted): void {
                $checked++;

                $reconciliation = $ledger->reconcile($wallet);

                if ($reconciliation->isBalanced()) {
                    return;
                }

                $drifted++;

                /*
                 * Logged at error rather than warning. A wallet whose cached
                 * balance disagrees with its entries is either money the
                 * platform thinks it owes and does not, or money it owes and
                 * has forgotten — and both are incidents rather than noise.
                 */
                Log::error('A wallet balance disagrees with its own ledger.', [
                    'wallet_id' => (string) $wallet->getKey(),
                    'customer_id' => (string) $wallet->customer_id,
                    'currency' => $wallet->currency,
                    // The re-derived figure, which is what any dispute is
                    // settled against: it is what a customer would arrive at
                    // by adding up their own statement.
                    'ledger_total_minor' => $ledger->recomputeBalance($wallet)->minorUnits(),
                    'cached_balance_minor' => $wallet->balance_minor,
                    'drift_minor' => $reconciliation->drift()->minorUnits(),
                ]);

                $this->error(sprintf(
                    'Wallet %s (%s) is out by %s.',
                    $wallet->getKey(),
                    $wallet->currency,
                    $reconciliation->drift()->format(),
                ));
            });

        $this->info(sprintf('Checked %d wallet(s); %d disagreed with their ledger.', $checked, $drifted));

        // A non-zero exit so a scheduler or a CI step notices. The drift is
        // already logged; this is what makes it visible to something watching
        // exit codes rather than log lines.
        return $drifted === 0 ? self::SUCCESS : self::FAILURE;
    }
}
