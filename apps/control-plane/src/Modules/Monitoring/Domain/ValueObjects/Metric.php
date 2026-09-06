<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Domain\ValueObjects;

use Lynomia\Modules\Monitoring\Domain\Enums\MetricType;
use Lynomia\Modules\Monitoring\Domain\Exceptions\InvalidMetricException;

/**
 * A metric family: one name, one type, one help string, and its samples.
 *
 * Names and label names are validated here rather than in the formatter,
 * because they are compile-time constants in the collectors and a bad one is a
 * bug that should surface the first time the code runs — not silently at 4am
 * when the exposition is rejected and every alert built on it goes quiet.
 *
 * Label *values* are not validated: they come from the database and may
 * legitimately contain anything a hostname or a pool name can contain. Making
 * them safe is the formatter's job.
 */
final readonly class Metric
{
    /** Prometheus metric names. Colons are reserved for recording rules, but are legal. */
    private const string NAME_PATTERN = '/\A[a-zA-Z_:][a-zA-Z0-9_:]*\z/';

    /** Label names, which unlike metric names may not contain a colon. */
    private const string LABEL_PATTERN = '/\A[a-zA-Z_][a-zA-Z0-9_]*\z/';

    /**
     * @param  list<MetricSample>  $samples
     */
    private function __construct(
        public string $name,
        public MetricType $type,
        public string $help,
        public array $samples,
    ) {}

    /**
     * @param  list<MetricSample>  $samples
     */
    public static function counter(string $name, string $help, array $samples): self
    {
        return self::make($name, MetricType::Counter, $help, $samples);
    }

    /**
     * @param  list<MetricSample>  $samples
     */
    public static function gauge(string $name, string $help, array $samples): self
    {
        return self::make($name, MetricType::Gauge, $help, $samples);
    }

    /**
     * A histogram, whose samples carry the "_bucket", "_sum" and "_count"
     * suffixes and whose bucket samples carry the "le" label.
     *
     * @param  list<MetricSample>  $samples
     */
    public static function histogram(string $name, string $help, array $samples): self
    {
        return self::make($name, MetricType::Histogram, $help, $samples);
    }

    /**
     * @param  list<MetricSample>  $samples
     */
    private static function make(string $name, MetricType $type, string $help, array $samples): self
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw InvalidMetricException::badMetricName($name);
        }

        $seen = [];

        foreach ($samples as $sample) {
            foreach (array_keys($sample->labels) as $label) {
                if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                    throw InvalidMetricException::badLabelName($name, $label);
                }
            }

            $key = $sample->seriesKey();

            if (isset($seen[$key])) {
                throw InvalidMetricException::duplicateSeries($name, $key);
            }

            $seen[$key] = true;
        }

        return new self($name, $type, $help, array_values($samples));
    }
}
