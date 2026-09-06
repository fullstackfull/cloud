<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;

/**
 * Provisioning outcomes and durations.
 *
 * Two queries, both GROUP BY, neither growing with the number of jobs.
 *
 * The duration histogram is computed entirely in SQL, as cumulative bucket
 * counts over every job that has ever finished. That makes it a genuine
 * Prometheus histogram — monotonically increasing, because a finished job never
 * unfinishes — so histogram_quantile() over rate() behaves exactly as it would
 * for an in-process histogram, without this application having to hold any
 * state between scrapes. Two application hosts serving the same numbers is the
 * property that makes it work behind a load balancer.
 */
final readonly class ProvisioningCollector implements MetricsCollector
{
    /**
     * Bucket boundaries in seconds.
     *
     * Chosen from what the platform actually does rather than from a default
     * ladder: a VPS create is tens of seconds, a hosting account is seconds, a
     * dedicated install is most of an hour. The 900 boundary is deliberate —
     * it is the default job timeout, so the bucket above it is exactly "jobs
     * that outlived their own deadline".
     *
     * @var list<int>
     */
    private const array BUCKETS = [5, 10, 30, 60, 120, 300, 600, 900, 1800, 3600];

    public function name(): string
    {
        return 'provisioning';
    }

    public function collect(): array
    {
        return [
            $this->jobsByStateAndKind(),
            $this->durations(),
        ];
    }

    private function jobsByStateAndKind(): Metric
    {
        $rows = DB::table('provisioning_jobs')
            ->selectRaw('status, kind, count(*) as total')
            ->groupBy('status', 'kind')
            ->get();

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($rows as $row) {
            /** @var object{status: string, kind: string, total: int|string} $row */
            $counts[$row->status.'|'.$row->kind] = (int) $row->total;
        }

        $samples = [];

        /*
         * The full cross product of status and kind — sixty-six series, most of
         * them zero on any given day. That is deliberate and it is cheap.
         *
         * The alternative, emitting only the combinations that have rows, means
         * `lynomia_provisioning_jobs_total{kind="create_vps", status="failed"}`
         * does not exist until the first VPS build fails. An alert on it would
         * therefore be silent for exactly as long as nothing has gone wrong,
         * and would start working only once it was too late to be a warning.
         */
        foreach (ProvisioningJobStatus::cases() as $status) {
            foreach (ProvisioningJobKind::cases() as $kind) {
                $samples[] = MetricSample::of(
                    ['status' => $status->value, 'kind' => $kind->value],
                    $counts[$status->value.'|'.$kind->value] ?? 0,
                );
            }
        }

        return Metric::gauge(
            'lynomia_provisioning_jobs_total',
            'Provisioning jobs by status and kind. A state count, not a monotonic counter: use delta() over terminal states.',
            $samples,
        );
    }

    private function durations(): Metric
    {
        /*
         * The elapsed time of every job that reached a finish, bucketed in the
         * database. `extract(epoch from interval)` returns numeric in
         * PostgreSQL, not a float, so the sum is exact rather than accumulating
         * representation error over a hundred thousand rows.
         */
        $finished = DB::table('provisioning_jobs')
            ->selectRaw('kind, extract(epoch from (finished_at - started_at)) as elapsed')
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at');

        $selects = ['kind'];
        $bindings = [];

        foreach (self::BUCKETS as $index => $boundary) {
            // Cumulative by construction: each bucket counts everything at or
            // below its boundary, which is what "le" means and what
            // histogram_quantile() assumes.
            $selects[] = "count(*) filter (where elapsed <= ?) as bucket_{$index}";
            $bindings[] = $boundary;
        }

        $selects[] = 'count(*) as observations';
        $selects[] = 'coalesce(sum(elapsed), 0) as elapsed_sum';

        $rows = DB::query()
            ->fromSub($finished, 'finished_jobs')
            ->selectRaw(implode(', ', $selects), $bindings)
            ->groupBy('kind')
            ->get();

        /** @var array<string, object> $byKind */
        $byKind = [];

        foreach ($rows as $row) {
            /** @var object{kind: string} $row */
            $byKind[$row->kind] = $row;
        }

        $samples = [];

        foreach (ProvisioningJobKind::cases() as $kind) {
            $row = $byKind[$kind->value] ?? null;

            foreach (self::BUCKETS as $index => $boundary) {
                $property = 'bucket_'.$index;

                $samples[] = new MetricSample(
                    ['kind' => $kind->value, 'le' => (string) $boundary],
                    (float) ($row?->{$property} ?? 0),
                    '_bucket',
                );
            }

            $observations = (float) ($row?->observations ?? 0);

            // The +Inf bucket is mandatory and must equal _count. A histogram
            // missing it is silently useless: histogram_quantile() has no
            // upper bound to interpolate towards and returns NaN.
            $samples[] = new MetricSample(
                ['kind' => $kind->value, 'le' => '+Inf'],
                $observations,
                '_bucket',
            );

            $samples[] = new MetricSample(
                ['kind' => $kind->value],
                (float) ($row?->elapsed_sum ?? 0),
                '_sum',
            );

            $samples[] = new MetricSample(
                ['kind' => $kind->value],
                $observations,
                '_count',
            );
        }

        return Metric::histogram(
            'lynomia_provisioning_duration_seconds',
            'Elapsed seconds from start to finish of provisioning jobs, by kind.',
            $samples,
        );
    }
}
