<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring\Concerns;

/**
 * A strict reader for the Prometheus text exposition format.
 *
 * Deliberately strict, and deliberately not a regex over the whole body. The
 * failure this guards against is a malformed line — an unescaped quote in a
 * hostname, a stray carriage return — which Prometheus punishes by rejecting
 * the entire scrape rather than the offending series. A lenient parser in the
 * tests would accept exactly the output that the real scraper refuses, so this
 * one fails on anything it is not certain about.
 */
trait ParsesExposition
{
    /**
     * @return array{
     *     types: array<string, string>,
     *     help: array<string, string>,
     *     samples: array<string, float>,
     *     labels: array<string, array<string, string>>
     * }
     */
    protected function parseExposition(string $body): array
    {
        $types = [];
        $help = [];
        $samples = [];
        $labels = [];

        foreach (explode("\n", $body) as $number => $line) {
            $context = sprintf('line %d: %s', $number + 1, $line);

            if ($line === '') {
                continue;
            }

            $this->assertStringNotContainsString("\r", $line, "Carriage return in exposition, {$context}");

            if (str_starts_with($line, '# HELP ')) {
                [$name, $text] = array_pad(explode(' ', substr($line, 7), 2), 2, '');
                $help[$name] = $text;

                continue;
            }

            if (str_starts_with($line, '# TYPE ')) {
                [$name, $type] = array_pad(explode(' ', substr($line, 7), 2), 2, '');
                $this->assertContains($type, ['counter', 'gauge', 'histogram', 'summary', 'untyped'], $context);
                $types[$name] = $type;

                continue;
            }

            $this->assertFalse(str_starts_with($line, '#'), "Unrecognised comment, {$context}");

            $matched = preg_match(
                '/\A(?<name>[a-zA-Z_:][a-zA-Z0-9_:]*)(?:\{(?<labels>.*)\})? (?<value>[^ ]+)\z/',
                $line,
                $parts,
            );

            $this->assertSame(1, $matched, "Malformed sample, {$context}");

            $parsedLabels = $this->parseLabels($parts['labels'] ?? '', $context);
            $key = $this->seriesKey($parts['name'], $parsedLabels);

            $this->assertArrayNotHasKey($key, $samples, "Duplicate series, {$context}");

            $samples[$key] = $this->parseValue($parts['value'], $context);
            $labels[$key] = $parsedLabels;
        }

        // Every sample must belong to a declared family. A series with no TYPE
        // is scraped as untyped, which silently turns a counter into something
        // rate() refuses to touch.
        foreach (array_keys($samples) as $key) {
            $family = $this->familyOf((string) $key, array_keys($types));
            $this->assertNotNull($family, "Sample with no declared family: {$key}");
        }

        return ['types' => $types, 'help' => $help, 'samples' => $samples, 'labels' => $labels];
    }

    /**
     * @param  array<string, string>  $labels
     */
    protected function seriesKey(string $name, array $labels): string
    {
        ksort($labels);

        $parts = [];

        foreach ($labels as $label => $value) {
            $parts[] = $label.'='.$value;
        }

        return $parts === [] ? $name : $name.'{'.implode(',', $parts).'}';
    }

    /**
     * @return array<string, string>
     */
    private function parseLabels(string $raw, string $context): array
    {
        if ($raw === '') {
            return [];
        }

        $labels = [];
        $offset = 0;

        while ($offset < strlen($raw)) {
            $matched = preg_match(
                '/\G(?<name>[a-zA-Z_][a-zA-Z0-9_]*)="(?<value>(?:[^"\\\\]|\\\\.)*)"(?:,|\z)/',
                $raw,
                $parts,
                0,
                $offset,
            );

            $this->assertSame(1, $matched, "Malformed label set, {$context}");

            $labels[$parts['name']] = $this->unescape($parts['value']);
            $offset += strlen($parts[0]);
        }

        return $labels;
    }

    /**
     * The inverse of the formatter's escaping, and only of that: an escape the
     * formatter would never emit is a parse failure, because it is one for
     * Prometheus too.
     */
    private function unescape(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\(.)/',
            function (array $match): string {
                return match ($match[1]) {
                    '\\' => '\\',
                    '"' => '"',
                    'n' => "\n",
                    default => $this->fail('Unknown escape sequence \\'.$match[1].' in a label value.'),
                };
            },
            $value,
        );
    }

    private function parseValue(string $raw, string $context): float
    {
        return match ($raw) {
            '+Inf' => INF,
            '-Inf' => -INF,
            'NaN' => NAN,
            default => (function () use ($raw, $context): float {
                $this->assertSame(1, preg_match('/\A-?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?\z/', $raw), "Malformed value, {$context}");

                return (float) $raw;
            })(),
        };
    }

    /**
     * @param  list<string>  $families
     */
    private function familyOf(string $key, array $families): ?string
    {
        $name = str_contains($key, '{') ? strstr($key, '{', true) : $key;
        $name = (string) $name;

        foreach ($families as $family) {
            if ($name === $family) {
                return $family;
            }

            // Histograms expose _bucket, _sum and _count series under the
            // family's own name.
            foreach (['_bucket', '_sum', '_count'] as $suffix) {
                if ($name === $family.$suffix) {
                    return $family;
                }
            }
        }

        return null;
    }
}
