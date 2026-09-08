<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Compute\Application\Actions\DetectVirtualMachineDrift;
use Lynomia\Modules\Compute\Application\Actions\SyncClusterInventory;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * One cluster's reconciliation, on the queue.
 *
 * A job per cluster rather than one command that walks the fleet, for two
 * reasons the brief for this work names explicitly: a cluster whose API is down
 * must not stop the others being reconciled, and a scheduler process that
 * talked to every hypervisor in sequence would hold a single point of failure
 * for the whole estate's visibility. The scheduler dispatches; workers do the
 * talking.
 *
 * Read-only at the provider. `SyncClusterInventory` refreshes what the platform
 * believes about the hardware, and `DetectVirtualMachineDrift` records where
 * the two disagree about machines. Neither creates or destroys anything at the
 * hypervisor, and this job must never grow a step that does: automatic
 * remediation of drift is how a reconciler with a stale view deletes a
 * customer's server.
 */
final class ReconcileCluster implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'infrastructure';

    /**
     * One attempt, then the next scheduled run.
     *
     * A hypervisor that cannot be reached now is unlikely to be reachable in
     * ten seconds, and reconciliation is not urgent: it is a picture of the
     * estate, taken regularly. Retrying would multiply calls against an API
     * that is already struggling.
     */
    public int $tries = 1;

    public function __construct(
        private readonly string $clusterId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(
        SyncClusterInventory $inventory,
        DetectVirtualMachineDrift $drift,
        SecretRedactor $redactor,
    ): void {
        $cluster = ComputeCluster::query()->find($this->clusterId);

        if ($cluster === null) {
            // Removed between the sweep and the worker. Nothing to reconcile.
            return;
        }

        try {
            $result = $inventory->execute($cluster);
            $recorded = $drift->execute($cluster);

            Log::info('Cluster reconciled.', [
                'cluster_id' => $this->clusterId,
                'nodes_reported' => $result->nodesReported,
                'drift_recorded' => $recorded,
            ]);
        } catch (ComputeProviderException $e) {
            /*
             * Logged, not rethrown. A cluster that cannot be reached is a fact
             * about the estate, not a failure of the sweep — and a failed job
             * here would be retried against an API that is already unhappy.
             * The absence of a recent successful reconciliation is what an
             * operator is alerted on.
             */
            Log::warning('A cluster could not be reconciled.', [
                'cluster_id' => $this->clusterId,
                'error' => $redactor->redactString($e->getMessage()),
            ]);
        }
    }
}
