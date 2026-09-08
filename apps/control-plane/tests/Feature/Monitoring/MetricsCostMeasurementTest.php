<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Monitoring\Domain\Services\MetricsRegistry;
use Lynomia\Modules\Monitoring\Infrastructure\MonitoringServiceProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prints what one scrape costs, so the performance report quotes a measurement
 * rather than an estimate.
 *
 * Not a gate — MetricsQueryBudgetTest is the gate, and it asserts both a
 * ceiling and that the count does not grow with the data. This exists so that
 * the number in docs/performance-report.md can be reproduced by running one
 * command, and so it is obvious when it is stale.
 */
#[Group('measurement')]
final class MetricsCostMeasurementTest extends TestCase
{
    #[Test]
    public function report_the_cost_of_one_scrape(): void
    {
        $this->app->register(MonitoringServiceProvider::class);
        config()->set('queue.default', 'database');

        $registry = $this->app->make(MetricsRegistry::class);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $start = hrtime(true);
        $metrics = $registry->collect();
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        $series = 0;

        foreach ($metrics as $metric) {
            $series += count($metric->samples);
        }

        fwrite(STDERR, sprintf(
            "\nMETRICS SCRAPE: %d metrics, %d series, %d queries, %.1f ms\n",
            count($metrics),
            $series,
            $queries,
            $elapsedMs,
        ));

        $this->assertGreaterThan(0, count($metrics));
    }
}
