<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Services;

use Lynomia\Modules\SharedHosting\Domain\DTOs\HostingPlacementRequest;
use Lynomia\Modules\SharedHosting\Domain\Enums\HostingNodeStatus;
use Lynomia\Modules\SharedHosting\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\SharedHosting\Domain\Exceptions\NoHostingCapacityException;
use Lynomia\Modules\SharedHosting\Domain\ValueObjects\HostingNodeScore;
use Lynomia\Modules\SharedHosting\Domain\ValueObjects\HostingPlacementDecision;
use Lynomia\Modules\SharedHosting\Domain\ValueObjects\HostingPlacementRejection;
use Lynomia\Modules\SharedHosting\Domain\ValueObjects\HostingScoreComponent;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;

/**
 * Decides which shared node an account goes on.
 *
 * This is weighted placement, not first-fit, and on a shared platform the
 * difference is larger than on a hypervisor. First-fit fills node 1 until it
 * refuses another account and then starts on node 2, which puts every customer
 * who bought in the platform's first month onto one machine — the maximum
 * possible blast radius, and the machine with no headroom left to absorb a
 * failure anywhere else.
 *
 * Eligibility and preference are kept strictly apart:
 *
 *  - a node that is draining, in maintenance, offline, closed to new accounts,
 *    UNLICENSED, past its disk threshold, past its account ceiling, past its
 *    load threshold, in the wrong region or unable to hold the package is
 *    EXCLUDED. It is never merely given a low score, because a low score still
 *    wins when it is the only score, and "the only node left" is exactly the
 *    situation in which placing on a node that is already at 95% disk does the
 *    most damage;
 *  - everything else is a weighted preference, tuned from config.
 *
 * **Disk is weighted hardest, and that is the whole policy.** A shared node
 * that runs out of disk does not degrade — MySQL stops writing, Dovecot stops
 * accepting mail, every PHP session write fails, and every site on the machine
 * breaks at the same moment. The customers who notice first are the ones who
 * have been there longest, because they have the most traffic, the most mail
 * and the most to lose. CPU load, by contrast, degrades gracefully: everything
 * gets slower and then recovers.
 *
 * Reads config('hosting.scheduler.*'):
 *
 *   max_disk_used_percent    — hard exclusion threshold, deliberately conservative
 *   max_accounts_per_node    — fleet default ceiling, overridden by a node's own max_accounts
 *   max_load_average         — hard exclusion threshold
 *   weights.disk_headroom    — heaviest term
 *   weights.account_headroom
 *   weights.load_headroom
 *   require_cloudlinux_for_limited_packages — see assessPackage()
 */
final readonly class HostingNodeScheduler
{
    /**
     * Used when config is missing entirely. The relative sizes carry the
     * policy: disk outweighs everything else put together.
     *
     * @var array<string, int>
     */
    private const array FALLBACK_WEIGHTS = [
        'disk_headroom' => 50,
        'account_headroom' => 30,
        'load_headroom' => 20,
    ];

    private const int FALLBACK_MAX_DISK_PERCENT = 75;

    private const float FALLBACK_MAX_LOAD = 8.0;

    private const int FALLBACK_MAX_ACCOUNTS = 250;

    /**
     * @throws NoHostingCapacityException
     */
    public function place(HostingPlacementRequest $request): HostingPlacementDecision
    {
        /** @var HostingPackage $package */
        $package = HostingPackage::query()->findOrFail($request->packageId);

        /** @var list<HostingNode> $nodes */
        $nodes = HostingNode::query()
            ->with('datacenter')
            // A stable secondary order so that two nodes which genuinely tie
            // resolve the same way on every run; an unordered tie-break makes
            // placement irreproducible and its bugs unrepeatable.
            ->orderBy('id')
            ->get()
            ->all();

        /** @var list<HostingPlacementRejection> $rejections */
        $rejections = [];

        /** @var list<HostingNode> $eligible */
        $eligible = [];

        foreach ($nodes as $node) {
            $rejection = $this->assess($node, $package, $request);

            if ($rejection !== null) {
                $rejections[] = $rejection;

                continue;
            }

            $eligible[] = $node;
        }

        if ($eligible === []) {
            throw NoHostingCapacityException::forPackage(
                $package->slug,
                $request->regionId,
                $this->tally($rejections),
            );
        }

        $candidates = $this->score($eligible, $package);

        return new HostingPlacementDecision($candidates[0], $candidates, $rejections);
    }

    /**
     * Every reason this node may not take the account, or null if it may.
     *
     * The order is deliberate: the conditions that are cheapest to check and
     * hardest to fix come first, so that an operator reading a tally of
     * rejections sees "unlicensed" rather than "disk" when both are true. The
     * two demand different actions and only one of them resolves on its own.
     */
    private function assess(
        HostingNode $node,
        HostingPackage $package,
        HostingPlacementRequest $request,
    ): ?HostingPlacementRejection {
        $nodeId = (string) $node->getKey();

        if (in_array($nodeId, $request->excludedNodeIds, true)) {
            return $this->reject($node, PlacementRejectionReason::Excluded, 'the caller excluded this node');
        }

        if ($request->panel !== null && $node->panel !== $request->panel) {
            /*
             * A hard filter, not a preference. Customers buy "cPanel hosting"
             * by name, their backups are panel-shaped, and their migration
             * tooling assumes one of the two. Placing a cPanel order onto a
             * DirectAdmin node delivers something the customer did not buy and
             * cannot restore their site into.
             */
            return $this->reject(
                $node,
                PlacementRejectionReason::PanelMismatch,
                sprintf('this node runs %s and the order is for %s', $node->panel->value, $request->panel->value),
            );
        }

        if (! $node->status->acceptsNewAccounts()) {
            return $this->reject(
                $node,
                $node->status === HostingNodeStatus::Maintenance
                    ? PlacementRejectionReason::InMaintenance
                    : PlacementRejectionReason::NodeNotAccepting,
                sprintf('the node is %s', $node->status->value),
            );
        }

        if (! $node->accepts_new_accounts) {
            return $this->reject(
                $node,
                PlacementRejectionReason::NodeNotAccepting,
                'an operator has closed this node to new accounts',
            );
        }

        if (! $node->isLicensed()) {
            /*
             * An exclusion, never a penalty, and never overridable. cPanel/WHM
             * and DirectAdmin are commercial products: an unlicensed node's
             * panel stops serving, and the accounts on it stop working. It
             * still answers its API and still looks healthy in the meantime,
             * which is precisely why this has to be checked rather than
             * inferred from health.
             */
            return $this->reject(
                $node,
                PlacementRejectionReason::Unlicensed,
                sprintf('the panel licence is %s', $node->licence_status ?? 'missing'),
            );
        }

        if ($request->regionId !== null && $node->datacenter?->region_id !== $request->regionId) {
            /*
             * A hosting account holds the customer's mail and database as well
             * as their files, so where it lives is a data-residency decision
             * rather than a latency one. A customer who chose Kuwait and was
             * placed in Frankfurt has had that decision made for them.
             */
            return $this->reject(
                $node,
                PlacementRejectionReason::WrongRegion,
                'the node is in a different region from the one the customer bought in',
            );
        }

        $diskPercent = $node->diskUsedPercent();
        $maxDisk = $this->maxDiskPercent();

        if ($diskPercent !== null && $diskPercent >= $maxDisk) {
            /*
             * The heaviest exclusion, and deliberately conservative. A shared
             * node that fills up stops accepting mail and breaks every site on
             * it simultaneously; there is no partial failure to absorb. The
             * threshold leaves room for the accounts already there to grow,
             * because they will.
             */
            return $this->reject(
                $node,
                PlacementRejectionReason::DiskThresholdExceeded,
                sprintf('disk is %.1f%% used and the threshold is %d%%', $diskPercent, $maxDisk),
            );
        }

        if ($node->freeAccountSlots() < 1) {
            return $this->reject(
                $node,
                PlacementRejectionReason::AccountLimitReached,
                sprintf('the node holds %d accounts and its ceiling is %d', $node->account_count, $node->accountLimit()),
            );
        }

        $maxLoad = $this->maxLoadAverage();

        if ($node->load_average !== null && $node->load_average > $maxLoad) {
            return $this->reject(
                $node,
                PlacementRejectionReason::LoadThresholdExceeded,
                sprintf('load average is %.2f and the threshold is %.2f', $node->load_average, $maxLoad),
            );
        }

        return $this->assessPackage($node, $package);
    }

    /**
     * Whether this node can actually serve this package.
     *
     * Two distinct questions:
     *
     *  - can it hold the disk the package promises? A 50 GiB package placed on
     *    a node with 20 GiB of headroom is a node that hits its threshold as
     *    soon as the customer uses what they bought;
     *
     *  - can it ENFORCE the limits the package promises? Only CloudLinux does,
     *    and the platform's stated position is that it records the limits
     *    either way and says plainly whether a node holds to them, rather than
     *    implying isolation it does not have. So this is an exclusion only when
     *    an operator has asked for it to be, and a node without CloudLinux
     *    otherwise stays eligible with the shortfall visible on the row.
     */
    private function assessPackage(HostingNode $node, HostingPackage $package): ?HostingPlacementRejection
    {
        $footprint = $package->diskFootprintMib();
        $free = $node->freeDiskMib();

        if ($footprint !== null && $free !== null && $free < $footprint) {
            return $this->reject(
                $node,
                PlacementRejectionReason::PackageIncompatible,
                sprintf('the package needs %d MiB and the node has %d MiB free', $footprint, $free),
            );
        }

        if ($package->requiresKernelIsolation()
            && ! $node->cloudlinux
            && (bool) config('hosting.scheduler.require_cloudlinux_for_limited_packages', false)
        ) {
            return $this->reject(
                $node,
                PlacementRejectionReason::PackageIncompatible,
                'the package promises kernel-enforced limits and this node has no CloudLinux licence',
            );
        }

        return null;
    }

    /**
     * @param  list<HostingNode>  $eligible
     * @return list<HostingNodeScore>
     */
    private function score(array $eligible, HostingPackage $package): array
    {
        $weights = $this->weights();
        $maxLoad = $this->maxLoadAverage();
        $footprint = $package->diskFootprintMib() ?? 0;

        $scores = [];

        foreach ($eligible as $node) {
            $scores[] = new HostingNodeScore($node, [
                new HostingScoreComponent(
                    'disk_headroom',
                    $this->diskHeadroomRatio($node, $footprint),
                    $weights['disk_headroom'],
                    $this->diskDetail($node, $footprint),
                ),
                new HostingScoreComponent(
                    'account_headroom',
                    // Measured against the node's own ceiling rather than the
                    // fleet's, because account density is what drives support
                    // load and noisy-neighbour complaints, and a small node's
                    // ceiling is small for a reason.
                    $node->accountLimit() < 1 ? 0.0 : $node->freeAccountSlots() / $node->accountLimit(),
                    $weights['account_headroom'],
                    sprintf('%d of %d slots free', $node->freeAccountSlots(), $node->accountLimit()),
                ),
                new HostingScoreComponent(
                    'load_headroom',
                    /*
                     * A node that has not reported a load average scores as if
                     * it were at the threshold rather than as if it were idle.
                     * Missing data must never be the best answer: the node
                     * most likely to have stopped reporting is the one that is
                     * struggling.
                     */
                    $node->load_average === null
                        ? 0.0
                        : max(0.0, 1 - ($node->load_average / max(0.01, $maxLoad))),
                    $weights['load_headroom'],
                    $node->load_average === null
                        ? 'the node has not reported a load average'
                        : sprintf('load %.2f against a threshold of %.2f', $node->load_average, $maxLoad),
                ),
            ]);
        }

        usort($scores, static function (HostingNodeScore $a, HostingNodeScore $b): int {
            // Descending by total, then ascending by id so ties are stable.
            return [$b->total(), (string) $a->node->getKey()] <=> [$a->total(), (string) $b->node->getKey()];
        });

        return $scores;
    }

    /**
     * Disk headroom measured AFTER this account's package lands, not before.
     *
     * The two rank a heterogeneous fleet differently, and only the second is
     * the constraint the node will actually run into. A node with 30 GiB free
     * looks comfortable until a 25 GiB package lands on it.
     */
    private function diskHeadroomRatio(HostingNode $node, int $footprintMib): float
    {
        $total = $node->disk_total_mib;
        $free = $node->freeDiskMib();

        if ($total === null || $total < 1 || $free === null) {
            // A node that has not reported its disk scores zero on the heaviest
            // term. It stays eligible — the operator may simply not have run a
            // sync yet — but it never wins against a node that has told us
            // where it stands.
            return 0.0;
        }

        return max(0.0, ($free - $footprintMib) / $total);
    }

    private function diskDetail(HostingNode $node, int $footprintMib): string
    {
        $free = $node->freeDiskMib();

        if ($free === null) {
            return 'the node has not reported its disk';
        }

        return sprintf('%d MiB free now, %d MiB after this account', $free, max(0, $free - $footprintMib));
    }

    private function reject(
        HostingNode $node,
        PlacementRejectionReason $reason,
        string $detail,
    ): HostingPlacementRejection {
        return new HostingPlacementRejection((string) $node->getKey(), $node->hostname, $reason, $detail);
    }

    private function maxDiskPercent(): int
    {
        $configured = (int) config('hosting.scheduler.max_disk_used_percent', self::FALLBACK_MAX_DISK_PERCENT);

        // Clamped: a threshold of 0 would exclude the whole fleet and a
        // threshold above 100 would disable the check entirely, and neither is
        // a tuning decision anybody makes on purpose.
        return max(1, min(100, $configured));
    }

    private function maxLoadAverage(): float
    {
        $configured = (float) config('hosting.scheduler.max_load_average', self::FALLBACK_MAX_LOAD);

        return $configured > 0 ? $configured : self::FALLBACK_MAX_LOAD;
    }

    /**
     * @return array<string, int>
     */
    private function weights(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = config('hosting.scheduler.weights', []);

        $weights = [];

        foreach (self::FALLBACK_WEIGHTS as $name => $fallback) {
            $value = $configured[$name] ?? $fallback;
            // Negative weights would invert a term — a node scoring worse for
            // having more free disk — which is never deliberate.
            $weights[$name] = is_numeric($value) ? max(0, (int) $value) : $fallback;
        }

        return $weights;
    }

    /**
     * @param  list<HostingPlacementRejection>  $rejections
     * @return array<string, int>
     */
    private function tally(array $rejections): array
    {
        $tally = [];

        foreach ($rejections as $rejection) {
            $tally[$rejection->reason->value] = ($tally[$rejection->reason->value] ?? 0) + 1;
        }

        return $tally;
    }

    /**
     * The fleet-wide ceiling, exposed so callers reporting capacity do not
     * have to re-read config with a different fallback than the scheduler
     * used.
     */
    public function fleetAccountCeiling(): int
    {
        return max(1, (int) config('hosting.scheduler.max_accounts_per_node', self::FALLBACK_MAX_ACCOUNTS));
    }
}
