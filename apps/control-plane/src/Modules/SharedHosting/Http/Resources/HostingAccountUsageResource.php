<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingAccount;

/**
 * Disk and bandwidth against quota, as of the last sync.
 *
 * Two properties of this payload matter more than the numbers in it.
 *
 * **A missing figure is null, never zero.** Panels answer with the numbers
 * absent far more often than anybody expects — a cPanel node rebuilding its
 * quota cache after a reboot, a DirectAdmin account whose statistics run has
 * not finished — and SyncAccountUsage refuses to write an empty answer for
 * exactly that reason. Rendering "not measured" as 0 here would undo that
 * care at the last possible moment: it puts a customer at 95% of quota on the
 * floor of their own usage graph, and the support ticket that follows is
 * answered by somebody who believes the graph.
 *
 * **Every figure is stamped, and the stamp is loud.** These are readings from
 * a point in time, not live values: nothing in this endpoint calls the panel,
 * because a per-request call to a node would let any customer generate load on
 * the machine their neighbours are running on, and would answer with a 502
 * whenever a node was busy. So the response says when it was measured and says
 * outright whether that is too old to rely on — a stale figure that is visibly
 * stale is a caveat, and a stale figure that is silently presented as current
 * is a wrong bill.
 *
 * Quotas come from the platform's copy of the package, which is what the
 * customer bought. The node enforces the package of the same name on the
 * panel; where they disagree the panel wins, and the reading is what shows it.
 *
 * @mixin HostingAccount
 */
final class HostingAccountUsageResource extends JsonResource
{
    /**
     * How old a reading may be before this endpoint calls it stale.
     *
     * Six hours, against a usage sweep that runs far more often than that: a
     * reading older than this does not mean the customer's usage has changed,
     * it means the platform has stopped hearing from the node, and the figures
     * should be read as history rather than as status.
     *
     * A constant rather than config because no config key exists for it and
     * this module does not own config/hosting.php; it belongs there.
     */
    public const int STALE_AFTER_SECONDS = 21_600;

    public function __construct(HostingAccount $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var HostingAccount $account */
        $account = $this->resource;

        $package = $account->package;

        return [
            'account_id' => $account->id,
            'username' => $account->username,

            'disk' => self::measure($account->disk_used_mib, $package?->disk_quota_mib, $package !== null),
            'bandwidth' => self::measure($account->bandwidth_used_mib, $package?->bandwidth_quota_mib, $package !== null),

            // Repeated in meta as an assessment; here as the fact that
            // qualifies every number above it.
            'measured_at' => $account->usage_synced_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        /** @var HostingAccount $account */
        $account = $this->resource;

        $measuredAt = $account->usage_synced_at;
        $ageSeconds = $measuredAt === null ? null : max(0, now()->getTimestamp() - $measuredAt->getTimestamp());

        return [
            'meta' => [
                'measured_at' => $measuredAt?->toIso8601String(),
                'age_seconds' => $ageSeconds,

                /*
                 * An account that has never reported is not "stale" in the
                 * sense of a figure that has aged — there is no figure. Said
                 * separately so a client can tell "we have not heard from this
                 * account yet" from "we stopped hearing from it".
                 */
                'never_measured' => $measuredAt === null,
                'stale' => $ageSeconds !== null && $ageSeconds > self::STALE_AFTER_SECONDS,
                'stale_after_seconds' => self::STALE_AFTER_SECONDS,

                // Stated so that no client builds a poll loop expecting this
                // endpoint to reach the node.
                'source' => 'last_panel_sync',
            ],
        ];
    }

    /**
     * One measurement against its entitlement.
     *
     * Both nullable fields here are three-state on purpose, for the same
     * reason `used_mib` is.
     *
     * `unlimited` is true only when a package is attached and that package
     * promises no ceiling. With no package to read, it is null — "the platform
     * cannot say" — and never true. A null quota means two entirely different
     * things: a plan sold as unmetered, and an account whose package row is
     * missing or was deleted out from under it. Reporting the second as
     * unlimited tells a customer their unknown ceiling is infinite, and a
     * portal that draws "Unlimited" beside a figure the platform is in fact
     * about to enforce a quota against is the same class of mistake as
     * rendering an unmeasured account at 0%.
     *
     * `used_percent` is null unless both halves are known and the quota is a
     * real number: a percentage of an unknown quota is a fabrication, and a
     * percentage of an unlimited one is a division by zero dressed up as a
     * progress bar.
     *
     * @param  bool  $quotaIsKnown  Whether a package is attached at all, which is what separates
     *                              "no ceiling" from "no answer".
     * @return array{used_mib: ?int, quota_mib: ?int, unlimited: ?bool, used_percent: ?float}
     */
    private static function measure(?int $used, ?int $quota, bool $quotaIsKnown): array
    {
        return [
            'used_mib' => $used,
            'quota_mib' => $quota,

            'unlimited' => $quotaIsKnown ? $quota === null : null,

            'used_percent' => $used !== null && $quota !== null && $quota > 0
                ? round($used / $quota * 100, 1)
                : null,
        ];
    }
}
