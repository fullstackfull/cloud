<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Application\Collectors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Throwable;

/**
 * Queue depth and the failed-job count.
 *
 * Depth is read from wherever the queue actually is, because the answer differs
 * by driver and the wrong answer is worse than none: production runs Redis,
 * local development runs the database, and the test suite runs sync. A
 * collector that only understood one of them would report a comfortable zero on
 * the other two while paid provisioning work piled up.
 *
 * Queue names are enumerated from configuration rather than discovered by
 * scanning Redis. SCAN over a production Redis on every scrape is a cost that
 * grows with the keyspace — the opposite of what a metrics endpoint may do —
 * and KEYS is worse. The configured names are a short, known list, so the
 * number of Redis commands is bounded by the number of queues, not by anything
 * that grows.
 */
final readonly class QueueCollector implements MetricsCollector
{
    public function name(): string
    {
        return 'queue';
    }

    public function collect(): array
    {
        return [
            $this->depth(),
            $this->failedJobs(),
        ];
    }

    private function depth(): Metric
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver", '');

        $samples = match ($driver) {
            'database' => $this->depthFromDatabase(),
            'redis' => $this->depthFromRedis($connection),
            /*
             * sync and null have no queue to be behind on, and a driver this
             * collector does not understand has a depth it cannot honestly
             * report. Zero is right for the first two. For the third it is a
             * lie, but the alternative — omitting the series — would make the
             * backlog alert silently un-fireable, and the driver is a
             * deployment constant that shows up the first time anyone looks.
             */
            default => $this->zeroed(),
        };

        return Metric::gauge(
            'lynomia_queue_depth',
            'Jobs waiting to be processed, per queue. Reserved (in-flight) jobs are excluded: a worker holding one is doing the work, not falling behind it.',
            $samples,
        );
    }

    /**
     * @return list<MetricSample>
     */
    private function depthFromDatabase(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::table('jobs')
            ->selectRaw('queue, count(*) as total')
            ->groupBy('queue')
            ->pluck('total', 'queue')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        $samples = [];

        foreach ($this->queueNames() as $queue) {
            $samples[] = MetricSample::of(['queue' => $queue], $counts[$queue] ?? 0);
            unset($counts[$queue]);
        }

        // A queue with work on it that nobody configured is worth seeing: it is
        // usually a job dispatched onto a name that no worker is listening to,
        // which is a backlog that never drains and never appears anywhere else.
        foreach ($counts as $queue => $total) {
            $samples[] = MetricSample::of(['queue' => (string) $queue], $total);
        }

        return $samples;
    }

    /**
     * @return list<MetricSample>
     */
    private function depthFromRedis(string $connection): array
    {
        try {
            $redis = Redis::connection((string) config("queue.connections.{$connection}.connection", 'default'));

            $samples = [];

            foreach ($this->queueNames() as $queue) {
                // Laravel's RedisQueue stores pending jobs in a list at
                // "queues:{name}" and jobs released with a delay in a sorted
                // set at "queues:{name}:delayed". Both are waiting work; both
                // are O(1) to measure.
                $pending = (int) $redis->llen("queues:{$queue}");
                $delayed = (int) $redis->zcard("queues:{$queue}:delayed");

                $samples[] = MetricSample::of(['queue' => $queue], $pending + $delayed);
            }

            return $samples;
        } catch (Throwable $e) {
            Log::warning('Could not read queue depth from Redis.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            /*
             * NaN, not zero. Zero would assert that the queues are empty at the
             * precise moment the platform has lost sight of them, and would
             * hold the backlog alert down through the outage. Prometheus treats
             * NaN as "no usable value": comparisons against it are false, the
             * graph shows a gap, and RedisUnavailable is the alert that fires.
             */
            return $this->nan();
        }
    }

    /**
     * @return list<MetricSample>
     */
    private function zeroed(): array
    {
        return array_map(
            static fn (string $queue): MetricSample => MetricSample::of(['queue' => $queue], 0),
            $this->queueNames(),
        );
    }

    /**
     * @return list<MetricSample>
     */
    private function nan(): array
    {
        return array_map(
            static fn (string $queue): MetricSample => new MetricSample(['queue' => $queue], NAN),
            $this->queueNames(),
        );
    }

    /**
     * Every queue the platform is configured to use.
     *
     * Horizon's supervisor configuration is the authoritative list when it is
     * present, because that is what the workers actually consume. The
     * connection's own default is included regardless: a job dispatched without
     * an explicit queue lands there whether or not a supervisor watches it, and
     * that unwatched queue is exactly the one worth having a series for.
     *
     * @return list<string>
     */
    private function queueNames(): array
    {
        $names = ['default'];

        $connection = (string) config('queue.default');
        $configured = config("queue.connections.{$connection}.queue");

        if (is_string($configured) && $configured !== '') {
            $names[] = $configured;
        }

        /** @var array<string, mixed> $supervisors */
        $supervisors = (array) config('horizon.defaults', []);

        foreach ($supervisors as $supervisor) {
            if (! is_array($supervisor)) {
                continue;
            }

            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                if (is_string($queue) && $queue !== '') {
                    $names[] = $queue;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function failedJobs(): Metric
    {
        /*
         * A real counter, unlike most of the `_total` series in this module:
         * rows are only ever appended to failed_jobs, and the one thing that
         * removes them — `queue:flush` — is a reset, which is precisely what
         * Prometheus's counter handling is built to absorb.
         */
        $total = (int) DB::table('failed_jobs')->count();

        return Metric::counter(
            'lynomia_failed_jobs_total',
            'Jobs that exhausted their retries and were moved to failed_jobs.',
            [MetricSample::of([], $total)],
        );
    }
}
