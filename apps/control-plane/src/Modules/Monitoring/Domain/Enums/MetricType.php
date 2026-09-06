<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Domain\Enums;

/**
 * The Prometheus metric types this platform exposes.
 *
 * Summary is deliberately absent. A summary computes quantiles inside the
 * process, and quantiles computed per-instance cannot be aggregated across the
 * two application hosts behind the load balancer — "the 95th percentile of the
 * two 95th percentiles" is not a percentile of anything. Where a distribution
 * is needed, a histogram is used and the quantile is computed at query time.
 */
enum MetricType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Histogram = 'histogram';
}
