<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use Lynomia\Modules\Monitoring\Domain\Exceptions\InvalidMetricException;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\MetricSample;
use Lynomia\Modules\Monitoring\Infrastructure\Formatters\PrometheusTextFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The formatter is the one place in this module where a bug is total rather
 * than local: a scrape is all-or-nothing, so a single malformed line does not
 * lose one series, it loses every metric the platform has — and every alert
 * built on them goes quiet rather than red.
 *
 * The inputs tested here are not hypothetical. Node names, pool slugs and
 * hosting hostnames are free text in the database, and an operator naming a
 * node `pve-"test"` or pasting a Windows path produces exactly the bytes that
 * end a label value early.
 */
final class PrometheusTextFormatterTest extends TestCase
{
    private PrometheusTextFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatter = new PrometheusTextFormatter;
    }

    #[Test]
    public function a_label_value_containing_a_quote_does_not_end_the_label_early(): void
    {
        $output = $this->render('pve-"test"');

        $this->assertStringContainsString('lynomia_test{node="pve-\"test\""} 1', $output);

        // The proof that matters: the line is still one well-formed sample with
        // a single quote-delimited label value, rather than three fragments a
        // parser would read as a truncated label followed by stray tokens.
        $line = $this->sampleLine($output);
        $this->assertSame(1, preg_match('/\Alynomia_test\{node="(?:[^"\\\\]|\\\\.)*"\} 1\z/', $line), $line);
    }

    #[Test]
    public function a_backslash_is_escaped_once_and_not_twice(): void
    {
        // A single backslash must become exactly two characters. The classic
        // bug is escaping the quote first and then escaping the backslash the
        // escape itself introduced, which turns one backslash into three.
        $output = $this->render('C:\\storage');

        $this->assertStringContainsString('node="C:\\\\storage"', $output);
        $this->assertStringNotContainsString('node="C:\\\\\\storage"', $output);
    }

    #[Test]
    public function a_backslash_immediately_before_a_quote_survives_a_single_pass(): void
    {
        // The adversarial case: a naive str_replace() sequence turns this into
        // an escaped backslash followed by an UNescaped quote, which ends the
        // label value and corrupts the rest of the line.
        $output = $this->render('weird\\"name');

        $this->assertStringContainsString('node="weird\\\\\\"name"', $output);

        $line = $this->sampleLine($output);
        $this->assertSame(1, preg_match('/\A\S+\{node="(?:[^"\\\\]|\\\\.)*"\} 1\z/', $line), $line);
    }

    #[Test]
    public function a_newline_in_a_label_value_cannot_split_the_record(): void
    {
        $output = $this->render("first\nsecond");

        $this->assertStringContainsString('node="first\\nsecond"', $output);

        // Three lines: HELP, TYPE, one sample. A literal newline would make it
        // four, and the fourth would be parsed as a nameless metric.
        $this->assertCount(3, array_filter(explode("\n", $output)));
    }

    #[Test]
    public function a_carriage_return_is_removed_rather_than_escaped(): void
    {
        /*
         * The format defines no \r escape, and Prometheus rejects escape
         * sequences it does not recognise — which would fail the whole scrape,
         * the very thing being avoided. A raw CR is equally unacceptable
         * because a line-oriented reader treats it as a break. Replacement is
         * the only remaining option.
         */
        $output = $this->render("pve\r01");

        $this->assertStringContainsString('node="pve 01"', $output);
        $this->assertStringNotContainsString("\r", $output);
        $this->assertStringNotContainsString('\\r', $output);
    }

    #[Test]
    public function a_null_byte_is_removed(): void
    {
        $output = $this->render("pve\x0001");

        $this->assertStringContainsString('node="pve 01"', $output);
        $this->assertStringNotContainsString("\x00", $output);
    }

    #[Test]
    public function a_tab_is_left_alone_because_it_is_legal(): void
    {
        $output = $this->render("pve\t01");

        $this->assertStringContainsString("node=\"pve\t01\"", $output);
    }

    #[Test]
    public function help_text_escapes_backslashes_and_newlines_but_not_quotes(): void
    {
        // A HELP line runs to the end of the line and is not quoted, so
        // escaping a quote there would put a stray backslash into the
        // documentation string an operator reads.
        $metric = Metric::gauge(
            'lynomia_test',
            'Path C:\\data, the "primary" one.'."\n".'Second line.',
            [MetricSample::of([], 1)],
        );

        $output = $this->formatter->render([$metric]);

        $this->assertStringContainsString(
            '# HELP lynomia_test Path C:\\\\data, the "primary" one.\\nSecond line.',
            $output,
        );
    }

    #[Test]
    public function labels_are_emitted_in_a_stable_order(): void
    {
        // Two scrapes of the same series must be byte-identical, so that a
        // human diffing two curl outputs during an incident sees only what
        // actually changed.
        $metric = Metric::gauge('lynomia_test', 'help.', [
            MetricSample::of(['zone' => 'a', 'node' => 'n1', 'dimension' => 'cpu'], 1),
        ]);

        $this->assertStringContainsString(
            'lynomia_test{dimension="cpu",node="n1",zone="a"} 1',
            $this->formatter->render([$metric]),
        );
    }

    #[Test]
    #[DataProvider('values')]
    public function values_are_formatted_the_way_prometheus_parses_them(float $value, string $expected): void
    {
        $metric = Metric::gauge('lynomia_test', 'help.', [new MetricSample([], $value)]);

        $this->assertStringContainsString('lynomia_test '.$expected."\n", $this->formatter->render([$metric]));
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function values(): iterable
    {
        // An operator reading a count of orders wants "1738", not "1738.0".
        yield 'whole numbers lose the decimal point' => [1738.0, '1738'];
        yield 'zero' => [0.0, '0'];
        yield 'negative whole' => [-42.0, '-42'];
        // Ratios must not be truncated by PHP's precision ini setting.
        yield 'ratio' => [0.8203125, '0.8203125'];
        // Runway for a pool with no measured consumption.
        yield 'positive infinity' => [INF, '+Inf'];
        yield 'negative infinity' => [-INF, '-Inf'];
        // Queue depth when the backend cannot be reached: not zero, which
        // would assert the queues are empty at the moment we cannot see them.
        yield 'not a number' => [NAN, 'NaN'];
    }

    #[Test]
    public function the_exposition_carries_help_and_type_before_every_family(): void
    {
        $output = $this->formatter->render([
            Metric::counter('lynomia_first_total', 'The first.', [MetricSample::of([], 1)]),
            Metric::gauge('lynomia_second', 'The second.', [MetricSample::of([], 2)]),
        ]);

        $this->assertSame([
            '# HELP lynomia_first_total The first.',
            '# TYPE lynomia_first_total counter',
            'lynomia_first_total 1',
            '# HELP lynomia_second The second.',
            '# TYPE lynomia_second gauge',
            'lynomia_second 2',
        ], array_values(array_filter(explode("\n", $output))));
    }

    #[Test]
    public function a_family_with_no_samples_still_declares_itself(): void
    {
        // A fleet with no hosting nodes has no per-node series, but the family
        // still has to announce its name and type — otherwise a dashboard
        // panel cannot distinguish "no nodes" from "metric renamed".
        $output = $this->formatter->render([
            Metric::gauge('lynomia_hosting_node_disk_ratio', 'help.', []),
        ]);

        $this->assertStringContainsString('# TYPE lynomia_hosting_node_disk_ratio gauge', $output);
    }

    #[Test]
    public function a_histogram_renders_its_bucket_sum_and_count_series(): void
    {
        $output = $this->formatter->render([
            Metric::histogram('lynomia_duration_seconds', 'help.', [
                new MetricSample(['le' => '10'], 3, '_bucket'),
                new MetricSample(['le' => '+Inf'], 5, '_bucket'),
                new MetricSample([], 42.5, '_sum'),
                new MetricSample([], 5, '_count'),
            ]),
        ]);

        $this->assertStringContainsString('lynomia_duration_seconds_bucket{le="10"} 3', $output);
        $this->assertStringContainsString('lynomia_duration_seconds_bucket{le="+Inf"} 5', $output);
        $this->assertStringContainsString('lynomia_duration_seconds_sum 42.5', $output);
        $this->assertStringContainsString('lynomia_duration_seconds_count 5', $output);
    }

    #[Test]
    public function two_samples_with_identical_labels_are_refused_at_construction(): void
    {
        /*
         * Prometheus rejects a duplicate series by discarding the whole scrape.
         * Two compute nodes called "pve-01" in different clusters is enough to
         * cause it, so the collision is caught here — where it is a test
         * failure — rather than in production, where it is every metric on the
         * platform disappearing at once.
         */
        $this->expectException(InvalidMetricException::class);

        Metric::gauge('lynomia_test', 'help.', [
            MetricSample::of(['node' => 'pve-01'], 1),
            MetricSample::of(['node' => 'pve-01'], 2),
        ]);
    }

    #[Test]
    public function a_metric_name_that_would_break_the_exposition_is_refused(): void
    {
        $this->expectException(InvalidMetricException::class);

        Metric::gauge('lynomia-test', 'help.', []);
    }

    #[Test]
    public function a_label_name_that_would_break_the_exposition_is_refused(): void
    {
        // Label names, unlike metric names, may not contain a colon.
        $this->expectException(InvalidMetricException::class);

        Metric::gauge('lynomia_test', 'help.', [MetricSample::of(['no:colons' => 'x'], 1)]);
    }

    private function render(string $node): string
    {
        return $this->formatter->render([
            Metric::gauge('lynomia_test', 'help.', [MetricSample::of(['node' => $node], 1)]),
        ]);
    }

    private function sampleLine(string $output): string
    {
        $lines = array_values(array_filter(
            explode("\n", $output),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));

        return $lines[0] ?? '';
    }
}
