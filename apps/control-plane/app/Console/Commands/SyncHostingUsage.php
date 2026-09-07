<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\SharedHosting\Application\Actions\SyncAccountUsage;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingAccountStatus;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Refreshes how much disk and bandwidth each hosting account is using.
 *
 * SyncAccountUsage was written and never called, so every account's usage was
 * whatever it had been when the account was created — which is to say zero.
 * The customer's own dashboard showed zero, quota warnings could not fire, and
 * an account filling a node's disk looked identical to an empty one right up
 * until the node ran out of space.
 *
 * Suspended accounts are included deliberately. A suspended account still
 * occupies disk, and the decision about whether to terminate it is made partly
 * on how much — so dropping it from the sweep would hide exactly the number
 * that decision needs. Terminated accounts are excluded: there is nothing left
 * on the node to measure.
 *
 * One account at a time, with each failure caught. A panel that has stopped
 * answering must cost the fleet one account's freshness, not the whole sweep;
 * a single throw here would leave every account after the failing one stale,
 * and which accounts those were would depend on the order of the query.
 */
final class SyncHostingUsage extends Command
{
    protected $signature = 'hosting:sync-usage
        {--limit=1000 : How many accounts to refresh in one pass}';

    protected $description = 'Refresh disk and bandwidth usage for every live hosting account';

    public function handle(SyncAccountUsage $sync): int
    {
        $accounts = HostingAccount::query()
            ->whereIn('status', [
                HostingAccountStatus::Active->value,
                HostingAccountStatus::Suspended->value,
            ])
            // Stalest first, so a sweep that cannot finish inside its window
            // still makes progress across the fleet instead of refreshing the
            // same accounts every run.
            ->orderByRaw('usage_synced_at asc nulls first')
            ->limit((int) $this->option('limit'))
            ->get();

        $refreshed = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                if ($sync->execute($account)) {
                    $refreshed++;
                }
            } catch (HostingProviderException $e) {
                $failed++;

                // Reported, not thrown: the sweep's job is to refresh what it
                // can. The message is the adapter's and already redacted by it.
                $this->components->warn(sprintf(
                    'Could not read usage for account %s: %s',
                    $account->getKey(),
                    $e->getMessage(),
                ));
            }
        }

        $this->line((string) json_encode([
            'command' => 'hosting:sync-usage',
            'considered' => $accounts->count(),
            'refreshed' => $refreshed,
            'failed' => $failed,
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
