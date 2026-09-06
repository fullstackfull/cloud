<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Domain\Contracts;

use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;

/**
 * A source of metrics for one domain.
 *
 * Two rules bind every implementation, and both exist because this interface
 * is called on a timer by something that will not wait:
 *
 *  1. **Aggregate in SQL. Never load models.** A collector that does
 *     `Order::all()` to count statuses works fine on the developer's machine
 *     with forty rows and takes the site down at ten thousand. Every count in
 *     this module is a GROUP BY, and the number of queries a collector issues
 *     does not grow with the number of rows, pools or nodes.
 *
 *  2. **Emit zero, not nothing.** A metric that disappears when there is no
 *     data cannot be alerted on: `lynomia_failed_payments_total > 10` never
 *     fires if the series is absent, and so does not distinguish "no failures"
 *     from "the collector is broken". Every collector emits the full set of
 *     label combinations it knows about, with zero where there is no row.
 */
interface MetricsCollector
{
    /**
     * Short identifier used in logs and in the collector-duration metric.
     */
    public function name(): string;

    /**
     * @return list<Metric>
     */
    public function collect(): array;
}
