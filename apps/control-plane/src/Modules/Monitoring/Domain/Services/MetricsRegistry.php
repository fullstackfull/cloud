<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Domain\Services;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Monitoring\Domain\Contracts\MetricsCollector;
use Lynomia\Modules\Monitoring\Domain\Exceptions\InvalidMetricException;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Throwable;

/**
 * Runs every registered collector and assembles one exposition's worth of
 * metric families.
 *
 * The important decision here is that a collector which throws does not take
 * the scrape down with it. A single broken query in, say, the revenue collector
 * would otherwise mean Prometheus receives a 500 and the platform has no
 * metrics at all — no queue depth, no provisioning outcomes, no IP runway —
 * because of a number nobody would have paged about.
 *
 * A failure is instead reported as data: `lynomia_metrics_collector_up` goes to
 * zero for that collector and everything else is still served. The alert on
 * that series is what turns a silently missing family back into something
 * visible, since absence itself cannot be alerted on.
 */
final class MetricsRegistry
{
    /** @var array<string, MetricsCollector> */
    private array $collectors = [];

    public function register(MetricsCollector ...$collectors): self
    {
        foreach ($collectors as $collector) {
            $this->collectors[$collector->name()] = $collector;
        }

        return $this;
    }

    /**
     * @return list<Metric>
     */
    public function collect(): array
    {
        /** @var array<string, Metric> $families */
        $families = [];

        /** @var list<MetricSample> $up */
        $up = [];

        /** @var list<MetricSample> $durations */
        $durations = [];

        foreach ($this->collectors as $name => $collector) {
            $startedAt = hrtime(true);

            try {
                foreach ($collector->collect() as $metric) {
                    if (isset($families[$metric->name])) {
                        throw InvalidMetricException::duplicateFamily($metric->name);
                    }

                    $families[$metric->name] = $metric;
                }

                $up[] = MetricSample::of(['collector' => $name], 1);
            } catch (Throwable $e) {
                /*
                 * Logged with the class and message but never the exception's
                 * bound parameters: a failing query in the revenue collector
                 * carries customer identifiers, and a metrics endpoint is not
                 * the place to leak them into the log.
                 */
                Log::error('A metrics collector failed.', [
                    'collector' => $name,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                $up[] = MetricSample::of(['collector' => $name], 0);
            }

            $durations[] = MetricSample::of(
                ['collector' => $name],
                (hrtime(true) - $startedAt) / 1_000_000_000,
            );
        }

        $families['lynomia_metrics_collector_up'] = Metric::gauge(
            'lynomia_metrics_collector_up',
            'Whether the last collection for this collector succeeded (1) or threw (0).',
            $up,
        );

        /*
         * Self-timing, because "collection must be cheap" is a claim that has
         * to be measurable. A collector that creeps from 5ms to 5s is invisible
         * until the scrape times out, and by then the endpoint installed to
         * detect outages is causing one.
         */
        $families['lynomia_metrics_collect_duration_seconds'] = Metric::gauge(
            'lynomia_metrics_collect_duration_seconds',
            'Wall-clock seconds the last collection took, per collector.',
            $durations,
        );

        // Sorted by family name so the exposition is stable between scrapes and
        // diffable by hand. Prometheus does not require it; humans do.
        ksort($families);

        return array_values($families);
    }
}
