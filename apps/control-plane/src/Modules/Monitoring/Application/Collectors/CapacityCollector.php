<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;

/**
 * How much of the fleet is already sold.
 *
 * This is allocation, not utilisation. The hypervisor reports what its guests
 * are using; this reports what the scheduler has committed, and the scheduler
 * places against the commitment. A cluster at 40% used and 95% allocated cannot
 * accept another order, and only one of those two numbers says so.
 *
 * Both ratios are computed in SQL. Doing the division in PHP would mean
 * selecting four columns per node and dividing them here, which is the same
 * work in a slower place, and would put the "what if the denominator is zero"
 * decision in two files instead of one.
 */
final readonly class CapacityCollector implements MetricsCollector
{
    public function name(): string
    {
        return 'capacity';
    }

    public function collect(): array
    {
        return [
            $this->computeNodes(),
            $this->hostingNodes(),
        ];
    }

    private function computeNodes(): Metric
    {
        /*
         * The cluster slug is carried as a label alongside the node name, and
         * that is not cosmetic. compute_nodes is unique on (cluster_id,
         * provider_name), not on provider_name alone: two clusters may each
         * have a node called "pve-01", which is entirely normal after an
         * acquisition or a second datacenter. Emitting both as
         * {node="pve-01", dimension="cpu"} would be two samples with identical
         * labels, and Prometheus rejects a duplicate series by discarding the
         * whole scrape — every metric on the platform lost because two machines
         * share a name.
         */
        $rows = DB::table('compute_nodes as n')
            ->join('compute_clusters as c', 'c.id', '=', 'n.cluster_id')
            ->selectRaw(<<<'SQL'
                c.slug as cluster,
                n.provider_name as node,
                case when n.cpu_cores > 0
                     then n.allocated_cpu_cores::numeric / n.cpu_cores else 0 end as cpu_ratio,
                case when n.memory_mib > 0
                     then n.allocated_memory_mib::numeric / n.memory_mib else 0 end as memory_ratio,
                case when n.storage_gib > 0
                     then n.allocated_storage_gib::numeric / n.storage_gib else 0 end as storage_ratio
                SQL)
            ->orderBy('c.slug')
            ->orderBy('n.provider_name')
            ->get();

        $samples = [];

        foreach ($rows as $row) {
            /** @var object{cluster: string, node: string, cpu_ratio: string, memory_ratio: string, storage_ratio: string} $row */
            $labels = ['cluster' => $row->cluster, 'node' => $row->node];

            $samples[] = MetricSample::of($labels + ['dimension' => 'cpu'], (float) $row->cpu_ratio);
            $samples[] = MetricSample::of($labels + ['dimension' => 'memory'], (float) $row->memory_ratio);
            $samples[] = MetricSample::of($labels + ['dimension' => 'storage'], (float) $row->storage_ratio);
        }

        return Metric::gauge(
            'lynomia_node_capacity_ratio',
            'Allocated over installed capacity per compute node and dimension. Allocation, not utilisation: the scheduler places against this.',
            $samples,
        );
    }

    private function hostingNodes(): Metric
    {
        /*
         * disk_total_mib and disk_used_mib are nullable: a node that has never
         * synced has neither. Those report zero rather than being skipped, so
         * the series exists from the moment the node does — a node that only
         * appears on the dashboard once it is already full is a node nobody
         * watched fill up.
         */
        $rows = DB::table('hosting_nodes')
            ->selectRaw(<<<'SQL'
                slug as node,
                case when coalesce(disk_total_mib, 0) > 0
                     then coalesce(disk_used_mib, 0)::numeric / disk_total_mib else 0 end as disk_ratio
                SQL)
            ->orderBy('slug')
            ->get();

        $samples = [];

        foreach ($rows as $row) {
            /** @var object{node: string, disk_ratio: string} $row */
            $samples[] = MetricSample::of(['node' => $row->node], (float) $row->disk_ratio);
        }

        return Metric::gauge(
            'lynomia_hosting_node_disk_ratio',
            'Used over total disk per shared hosting node, as the control plane sees it. Placement stops at the configured threshold.',
            $samples,
        );
    }
}
