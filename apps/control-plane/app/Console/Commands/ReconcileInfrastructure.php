<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Compute\Application\Jobs\ReconcileCluster;
use Lynomia\Modules\Compute\Domain\Enums\ClusterStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;

/**
 * Asks every cluster what it actually has.
 *
 * The scheduler's job here is only to fan out: one queued job per cluster, so
 * that a hypervisor which is down delays nothing but its own reconciliation,
 * and so that no single process holds a conversation with every machine the
 * platform owns.
 *
 * The platform could record drift long before it could detect any: RecordDrift
 * was written, tested, and called by nothing. This is the other half.
 */
final class ReconcileInfrastructure extends Command
{
    protected $signature = 'infrastructure:reconcile
        {--cluster= : Reconcile only this cluster}';

    protected $description = 'Refresh inventory and detect drift for every compute cluster';

    public function handle(): int
    {
        $clusters = ComputeCluster::query()
            ->when(
                is_string($this->option('cluster')) && $this->option('cluster') !== '',
                fn ($query) => $query->whereKey($this->option('cluster')),
                // Degraded clusters are reconciled too, and deliberately: a
                // cluster is often marked degraded *because* something drifted,
                // and stopping the sweep there would take away the picture at
                // the moment it is most needed. Offline and maintenance are
                // excluded, because there is nobody at the other end.
                fn ($query) => $query->whereIn('status', [
                    ClusterStatus::Active->value,
                    ClusterStatus::Degraded->value,
                ]),
            )
            ->pluck('id');

        foreach ($clusters as $id) {
            ReconcileCluster::dispatch((string) $id);
        }

        $this->line(json_encode([
            'command' => 'infrastructure:reconcile',
            'clusters_dispatched' => $clusters->count(),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
