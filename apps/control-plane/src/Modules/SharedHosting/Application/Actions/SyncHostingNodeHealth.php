<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Application\Actions;

use Lynomia\Modules\SharedHosting\Domain\Exceptions\HostingProviderException;
use Lynomia\Modules\SharedHosting\Infrastructure\HostingProviderFactory;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * Asks a node how it is, and whether its panel is still licensed.
 *
 * The scheduler has always refused to place an account on a node whose licence
 * is not valid, and has always weighted disk hardest. Both decisions read
 * columns that nothing ever wrote: `panel_licensed`, `licence_status`,
 * `licence_checked_at`, the disk readings and the load average were set once
 * by a seeder and then believed for ever. A licence that lapsed in March was
 * still valid to the platform in December, and every account placed after that
 * is a customer whose site stops serving when the panel's grace period ends.
 *
 * ---------------------------------------------------------------------------
 * What this never writes
 * ---------------------------------------------------------------------------
 *
 * `account_count`. That column is what the platform has committed, not what
 * the panel can see, and the difference is a create in flight: the panel does
 * not know about an account that is still being made, so a health sync that
 * wrote its number would free a slot that is genuinely spoken for and let the
 * scheduler place a second account into it. The node's own count is recorded
 * as an observation for an operator to read, and nothing else.
 *
 * ---------------------------------------------------------------------------
 * A node that will not answer
 * ---------------------------------------------------------------------------
 *
 * It is marked unlicensed, not left alone. That is the whole point of
 * {@see LicenceStatus}'s pessimistic default: an unreachable node is one the
 * platform cannot prove is licensed, and treating silence as "still fine" is
 * what turns a lapsed licence into a fleet-wide outage weeks later. The error
 * is kept on the row so an operator sees why.
 */
final readonly class SyncHostingNodeHealth
{
    public function __construct(
        private HostingProviderFactory $providers,
    ) {}

    /**
     * @return bool whether the node answered
     */
    public function execute(HostingNode $node): bool
    {
        $panel = $this->providers->for($node);

        try {
            $health = $panel->nodeHealth($node);
            $licence = $panel->licenceStatus($node);
        } catch (HostingProviderException $e) {
            $node->forceFill([
                'panel_licensed' => false,
                'licence_status' => 'unconfirmed',
                'licence_checked_at' => now(),
                'last_synced_at' => now(),
                'last_sync_error' => $e->getMessage(),
            ])->save();

            return false;
        }

        $node->forceFill(array_filter([
            'panel_version' => $health->panelVersion,
            'disk_total_mib' => $health->diskTotalMib,
            'disk_used_mib' => $health->diskUsedMib,
            'load_average' => $health->loadOne,
        ], static fn (mixed $value): bool => $value !== null) + [
            'panel_licensed' => $licence->valid,
            // The vendor's own word, kept verbatim: "expired" and
            // "unconfirmed" are different things to the person on the phone
            // to the vendor.
            'licence_status' => $licence->state ?? ($licence->valid ? 'valid' : 'invalid'),
            'licence_checked_at' => now(),
            'last_synced_at' => now(),
            'last_sync_error' => $health->online ? null : 'the node reported itself offline',
        ])->save();

        return $health->online;
    }
}
