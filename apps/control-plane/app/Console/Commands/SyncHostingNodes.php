<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\SharedHosting\Application\Actions\SyncHostingNodeHealth;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;
use Throwable;

/**
 * The hosting fleet's regular check-up.
 *
 * Compute clusters have had a reconciler since Phase 29 and the hosting fleet
 * had nothing: no node's disk, load, panel version or licence was ever read
 * after it was first entered by hand. The scheduler was making placement
 * decisions from a snapshot taken at seed time.
 *
 * A node that fails is not allowed to stop the sweep. The failure modes here
 * are precisely the ones that hit several nodes at once — a licence server
 * that is down, a network partition — and a run that aborted on the first one
 * would leave the rest of the fleet unsynced for another cycle, which is how
 * one unreachable node becomes a fleet-wide blind spot.
 */
final class SyncHostingNodes extends Command
{
    protected $signature = 'hosting:sync-nodes {--node= : One node id, instead of the whole fleet}';

    protected $description = 'Read disk, load and licence state from every hosting node.';

    public function handle(SyncHostingNodeHealth $sync): int
    {
        $nodes = HostingNode::query()
            ->when($this->option('node') !== null, fn ($query) => $query->whereKey($this->option('node')))
            ->orderBy('slug')
            ->get();

        if ($nodes->isEmpty()) {
            $this->info('No hosting nodes to sync.');

            return self::SUCCESS;
        }

        $answered = 0;
        $silent = 0;

        foreach ($nodes as $node) {
            try {
                if ($sync->execute($node)) {
                    $answered++;

                    continue;
                }
            } catch (Throwable $e) {
                $this->warn(sprintf('%s: %s', $node->slug, $e->getMessage()));
            }

            $silent++;
        }

        $this->info(sprintf('%d node(s) answered, %d did not.', $answered, $silent));

        /*
         * A non-zero exit when any node is silent, so a scheduled run shows up
         * as failed in the scheduler's own liveness metric rather than as a
         * quiet success that happened to sync nothing.
         */
        return $silent === 0 ? self::SUCCESS : self::FAILURE;
    }
}
