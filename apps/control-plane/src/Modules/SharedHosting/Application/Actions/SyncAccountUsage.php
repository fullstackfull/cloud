<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\SharedHosting\Domain\DTOs\AccountUsage;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Refreshes what the platform believes an account is using.
 *
 * One rule governs this whole action: **a missing figure is never written.**
 *
 * Panels answer with the numbers absent far more often than anybody expects. A
 * cPanel node rebuilding its quota cache after a reboot returns a summary with
 * no disk figure; DirectAdmin returns an empty usage block for an account
 * whose statistics run has not completed; a node under load answers the config
 * call and times out on the usage one. In every case the correct reading is
 * "unknown", and the tempting shortcut — treat absent as zero — is wrong in
 * the most expensive possible direction:
 *
 *  - a customer at 95% of quota is recorded at 0%, so quota enforcement stops
 *    and the account fills the node's disk, which takes every site on that
 *    machine down at once;
 *  - metered bandwidth resets to zero, so the overage that was about to be
 *    billed is not, and the month's revenue quietly disappears;
 *  - the usage graph the customer looks at drops to the floor, and the support
 *    ticket that follows is answered by somebody who believes the graph.
 *
 * So a reading with no measurements in it leaves the record exactly as it was,
 * including usage_synced_at — a sync that learned nothing is not a sync, and
 * stamping it would hide a node that has stopped reporting behind a timestamp
 * that says everything is fine.
 */
final readonly class SyncAccountUsage
{
    public function __construct(
        private HostingProviderFactory $providers,
    ) {}

    /**
     * @return bool Whether anything was written.
     *
     * @throws HostingProviderException
     */
    public function execute(HostingAccount $account): bool
    {
        $node = $account->node()->firstOrFail();

        $usage = $this->providers->for($node)->accountUsage($node, $account->username);

        return $this->apply($account, $usage);
    }

    /**
     * Write only what the panel actually said.
     */
    public function apply(HostingAccount $account, AccountUsage $usage): bool
    {
        if (! $usage->hasAnyMeasurement()) {
            return false;
        }

        // Built field by field rather than as a whole-row update: the panel
        // may have answered about disk and said nothing about bandwidth, and a
        // whole-row write would null the half it did not mention.
        $changes = array_filter([
            'disk_used_mib' => $usage->diskUsedMib,
            'bandwidth_used_mib' => $usage->bandwidthUsedMib,
            'ssl_status' => $usage->sslStatus,
            'ssl_expires_at' => $usage->sslExpiresAt,
        ], static fn (mixed $value): bool => $value !== null);

        if ($changes === []) {
            /*
             * The panel reported quotas but no consumption — an account that
             * exists and has never been measured. There is nothing to record,
             * and stamping the sync time would claim otherwise.
             */
            return false;
        }

        $account->forceFill([...$changes, 'usage_synced_at' => now()])->save();

        return true;
    }
}
