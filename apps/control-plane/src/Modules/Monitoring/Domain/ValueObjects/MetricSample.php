<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Domain\ValueObjects;

/**
 * One value of one metric, with the labels that identify it.
 *
 * Label values are stored exactly as they came from the database. Escaping is
 * the formatter's job and happens once, at the boundary — a value escaped here
 * as well would be escaped twice, and a hostname containing a backslash would
 * arrive in Prometheus with two.
 */
final readonly class MetricSample
{
    /**
     * @param  array<string, string>  $labels
     * @param  string  $nameSuffix  Appended to the family name for the composite
     *                              series a histogram is made of: "_bucket",
     *                              "_sum", "_count". Empty for everything else.
     */
    public function __construct(
        public array $labels,
        public float $value,
        public string $nameSuffix = '',
    ) {}

    /**
     * @param  array<string, string>  $labels
     */
    public static function of(array $labels, float|int $value): self
    {
        return new self($labels, (float) $value);
    }

    /**
     * A stable identity for the sample's label set, used to detect two samples
     * that would collide into one series.
     */
    public function seriesKey(): string
    {
        $labels = $this->labels;
        ksort($labels);

        $parts = [];
        foreach ($labels as $name => $value) {
            $parts[] = $name.'='.$value;
        }

        return $this->nameSuffix.'{'.implode(',', $parts).'}';
    }
}
