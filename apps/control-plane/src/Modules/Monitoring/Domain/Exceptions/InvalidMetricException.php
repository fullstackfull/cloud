<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A metric that could not be exposed without producing a malformed exposition.
 *
 * These are programming errors, not runtime conditions: a metric name is a
 * constant in a collector, never user input. They are exceptions rather than
 * silent skips because a scrape that quietly drops a series looks exactly like
 * a platform where nothing is wrong, and the alert built on that series simply
 * never fires.
 */
final class InvalidMetricException extends DomainException
{
    public function errorCode(): string
    {
        return 'monitoring.invalid_metric';
    }

    public function httpStatus(): int
    {
        return 500;
    }

    public static function badMetricName(string $name): self
    {
        return (new self(sprintf(
            'Metric name "%s" is not a valid Prometheus metric name.',
            $name,
        )))->withContext(['name' => $name]);
    }

    public static function badLabelName(string $metric, string $label): self
    {
        return (new self(sprintf(
            'Label name "%s" on metric "%s" is not a valid Prometheus label name.',
            $label,
            $metric,
        )))->withContext(['metric' => $metric, 'label' => $label]);
    }

    /**
     * Two families with the same name in one exposition make the whole scrape
     * ambiguous: Prometheus takes the first and discards the rest, so a
     * collector added by a later module silently loses to one added earlier.
     */
    public static function duplicateFamily(string $name): self
    {
        return (new self(sprintf(
            'Metric family "%s" was registered more than once.',
            $name,
        )))->withContext(['name' => $name]);
    }

    /**
     * Two samples with identical label sets are a duplicate series, which
     * Prometheus rejects for the entire scrape — not just for the offending
     * metric. One node name reused across two clusters is enough to cause it.
     */
    public static function duplicateSeries(string $name, string $labels): self
    {
        return (new self(sprintf(
            'Metric "%s" emitted two samples with identical labels: %s',
            $name,
            $labels,
        )))->withContext(['name' => $name, 'labels' => $labels]);
    }
}
