<?php

declare(strict_types=1);

namespace Lynomia\Modules\Monitoring\Infrastructure\Formatters;

use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;

/**
 * Renders metric families as the Prometheus text exposition format (0.0.4).
 *
 * Almost all of this class is about escaping, because the exposition format is
 * line-oriented and unquoted-by-default, and a scrape is all-or-nothing: a
 * single malformed line does not lose one series, it makes Prometheus reject
 * the entire response. The platform then has no metrics at all, and every alert
 * built on them goes quiet rather than red — the worst possible failure mode
 * for a monitoring system.
 *
 * The values that can break it are not hypothetical. Node names, pool slugs and
 * hosting hostnames are free text in the database, and an operator who names a
 * node `pve-"test"` or pastes a Windows path into a label produces exactly the
 * bytes that end a label value early and turn the rest of the line into
 * garbage.
 *
 * The format defines exactly three escapes inside a label value — backslash,
 * double quote and line feed — and no way at all to represent the other control
 * characters, so those are replaced rather than escaped. Inventing a `\r`
 * escape would be worse than dropping the byte: Prometheus's parser rejects
 * escape sequences it does not know, which is the whole-scrape failure again.
 */
final readonly class PrometheusTextFormatter
{
    /**
     * The exact Content-Type Prometheus expects. The version parameter is not
     * decoration: without it some clients fall back to guessing, and a guess
     * that lands on OpenMetrics changes what a trailing "# EOF" means.
     */
    public const string CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * Escapes inside a label value, applied in one pass.
     *
     * strtr() with an array replaces each matched substring once and never
     * re-examines what it has written, which is the property that matters here:
     * a naive sequence of str_replace() calls would turn a single backslash
     * into a backslash-n and then escape that backslash again.
     */
    private const array LABEL_ESCAPES = [
        '\\' => '\\\\',
        '"' => '\\"',
        "\n" => '\\n',
    ];

    /**
     * HELP text escapes. Note the absence of the double quote: a HELP line runs
     * to the end of the line and is not quoted, so escaping a quote there would
     * put a literal backslash into the documentation string.
     */
    private const array HELP_ESCAPES = [
        '\\' => '\\\\',
        "\n" => '\\n',
    ];

    /**
     * @param  list<Metric>  $metrics
     */
    public function render(array $metrics): string
    {
        $out = '';

        foreach ($metrics as $metric) {
            $out .= '# HELP '.$metric->name.' '.$this->escapeHelp($metric->help)."\n";
            $out .= '# TYPE '.$metric->name.' '.$metric->type->value."\n";

            foreach ($metric->samples as $sample) {
                $out .= $this->renderSample($metric->name, $sample);
            }
        }

        return $out;
    }

    private function renderSample(string $family, MetricSample $sample): string
    {
        return $family
            .$sample->nameSuffix
            .$this->renderLabels($sample->labels)
            .' '
            .$this->formatValue($sample->value)
            ."\n";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function renderLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        // Sorted so that two scrapes of the same series produce byte-identical
        // lines. Prometheus does not care about label order, but a human
        // diffing two curl outputs during an incident very much does.
        ksort($labels);

        $parts = [];

        foreach ($labels as $name => $value) {
            $parts[] = $name.'="'.$this->escapeLabelValue($value).'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    private function escapeLabelValue(string $value): string
    {
        return $this->stripUnrepresentableControls(strtr($value, self::LABEL_ESCAPES));
    }

    private function escapeHelp(string $help): string
    {
        return $this->stripUnrepresentableControls(strtr($help, self::HELP_ESCAPES));
    }

    /**
     * Replaces the control characters the format cannot carry.
     *
     * Everything from C0 except tab, which is legal and harmless, and except
     * line feed, which has already become a two-character escape by the time
     * this runs. A carriage return is the one that matters in practice: it
     * survives a copy-paste from a Windows terminal into a node name, and a
     * raw CR mid-line splits the record for any parser that reads lines rather
     * than bytes.
     *
     * No /u modifier: these are C0 bytes, which never appear inside a
     * multi-byte UTF-8 sequence, and a UTF-8 mode match would return null on
     * the one input that most needs cleaning.
     */
    private function stripUnrepresentableControls(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', ' ', $value);
    }

    /**
     * Formats a value the way Prometheus parses one.
     *
     * Integral values print without a decimal point, because a count of orders
     * reading "1738" rather than "1738.0" is what an operator expects when they
     * curl the endpoint. Everything else round-trips through the shortest
     * representation that parses back to the same double — json_encode uses
     * PHP's serialize_precision of -1, which is exactly that. Casting to string
     * instead would apply the `precision` ini setting and silently truncate a
     * ratio at fourteen significant digits.
     */
    private function formatValue(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        // 2^53: beyond it, doubles cannot represent consecutive integers and
        // printing one as an integer would assert a precision that is not there.
        if ($value === floor($value) && abs($value) < 9007199254740992.0) {
            return (string) (int) $value;
        }

        $encoded = json_encode($value);

        return $encoded === false ? sprintf('%.17G', $value) : $encoded;
    }
}
