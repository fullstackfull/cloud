<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Monitoring\Domain\Services\MetricsRegistry;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No rule anywhere may read a series this platform does not export.
 *
 * ===========================================================================
 * WHY THIS EXISTS BESIDE THE BACKUP GATE
 * ===========================================================================
 *
 * {@see EveryBackupAlertMetricHasAProducerTest} found the failure and fixed it
 * for one file: six alerts in `backups.yml` reading series nothing produced,
 * silent because a rule over an absent series evaluates to an empty vector and
 * an empty vector is what a passing rule looks like. Everything true about
 * that is true of the other five rule files — `platform.yml` watches the
 * provisioning engine, `business.yml` watches revenue and address capacity,
 * `control-center.yml` watches credentials and licences — and nothing was
 * holding those to the same standard.
 *
 * Gap 8 found the gate narrower than the risk, which is its own kind of gap:
 * the one bug class known to be silent was guarded in one sixth of the place
 * it can occur. The current state was clean when this was written, and that is
 * exactly when to write the gate — a metric renamed next month is caught here
 * rather than discovered during an incident that produced no alert.
 *
 * ===========================================================================
 * WHAT IT ASKS, AND WHO IT ASKS
 * ===========================================================================
 *
 * The producer set comes from a **booted application's** registry, not from
 * reading collector source. That distinction is the whole lesson of
 * {@see TheApplicationActuallyRegistersItsCollectorsTest}: every collector can
 * be correct, registered with its provider, and exported by nothing, because
 * the provider itself was never loaded. So this resolves the registry the way
 * a scrape would and registers nothing of its own.
 *
 * Only the forward direction is asserted here. The reverse — a series exported
 * and alerted on by nobody — is a judgement about what deserves paging, and
 * the backup gate asks it where the answer matters most. Sixty families across
 * fifteen collectors would need sixty written reasons, most of them "this is
 * dashboard material", and a list like that stops being read. What is not
 * negotiable is the direction that is silent.
 *
 * Recorded series are not scanned for: they are spelled in the
 * `namespace:metric:aggregation` convention rather than `lynomia_*`, and a
 * recording rule that reads a `lynomia_*` series is checked here like any
 * other rule, because a recorded series computed from an absent one is absent
 * too, one step further from anybody noticing.
 */
final class EveryAlertMetricHasAProducerTest extends TestCase
{
    private const string RULES = '/infrastructure/monitoring/prometheus/rules';

    /**
     * Series a rule reads that this platform is not the producer of.
     *
     * Empty, and the emptiness is the point: every `lynomia_*` name in every
     * rule file today is one a collector exports. An entry here would have to
     * name something else that writes the series — a remote-write source, an
     * exporter outside this repository — and say so.
     *
     * @var array<string, string>
     */
    private const array PRODUCED_ELSEWHERE = [];

    #[Test]
    public function every_lynomia_series_any_rule_reads_is_exported_by_a_booted_application(): void
    {
        $read = $this->seriesTheRulesRead();
        $exported = $this->seriesTheApplicationExports();

        $this->assertNotSame([], $read, 'No lynomia_* series was found in any rule file. Either the rules moved or this scanner is broken, and a scanner that finds nothing passes for the wrong reason.');

        $orphans = array_values(array_diff(array_keys($read), $exported, array_keys(self::PRODUCED_ELSEWHERE)));

        $this->assertSame(
            [],
            $orphans,
            "These series are read by a Prometheus rule and exported by nothing:\n  "
            .implode("\n  ", array_map(
                static fn (string $name): string => $name.'  ('.implode(', ', $read[$name]).')',
                $orphans,
            ))
            ."\n\nA rule over an absent series is silent rather than failing: it evaluates to an empty vector, "
            .'which on a dashboard and in an alert list is indistinguishable from a rule that is passing. '
            .'Export the series, delete the rule, or record in PRODUCED_ELSEWHERE what outside this repository writes it.',
        );
    }

    #[Test]
    public function every_rule_file_is_scanned_rather_than_the_one_that_was_remembered(): void
    {
        /*
         * The failure mode of this test itself. A scanner pointed at a single
         * file passes for every series in the other five, and that is how the
         * gate it replaces came to cover one sixth of the risk. So the file
         * list is read off the directory and asserted to contain every file
         * that is there — if somebody adds `domains.yml`, this covers it the
         * moment it lands rather than when somebody remembers.
         */
        $files = $this->ruleFiles();

        $this->assertGreaterThanOrEqual(
            6,
            count($files),
            'Fewer rule files were found than the repository is known to have. A scanner that reads fewer files than exist is a gate with holes in it.',
        );

        foreach (['backups.yml', 'business.yml', 'control-center.yml', 'platform.yml', 'recording.yml'] as $expected) {
            $this->assertContains(
                $expected,
                array_map('basename', $files),
                sprintf('%s was not picked up by the scanner.', $expected),
            );
        }
    }

    /**
     * Every rule file, read off the directory.
     *
     * @return list<string>
     */
    private function ruleFiles(): array
    {
        $directory = dirname(__DIR__, 4).self::RULES;

        $this->assertDirectoryExists($directory, 'The Prometheus rules are not where this test expects them. If they moved, move this scanner with them rather than deleting the assertion.');

        $files = glob($directory.'/*.yml') ?: [];
        sort($files);

        return array_values($files);
    }

    /**
     * Each `lynomia_*` series any rule reads, and which files read it.
     *
     * A text scan of the `expr:` blocks rather than a YAML parse, for the
     * reason the backup gate gives: the expressions are PromQL inside YAML
     * strings, and what is wanted is every identifier mentioned anywhere in an
     * expression, including inside `absent()` and inside folded scalars.
     * Comments are excluded by construction — several rule files discuss
     * series names in prose above the rule, and a scan of the whole file would
     * read those as usage.
     *
     * @return array<string, list<string>>
     */
    private function seriesTheRulesRead(): array
    {
        $found = [];

        foreach ($this->ruleFiles() as $path) {
            foreach ($this->seriesIn((string) file_get_contents($path)) as $name) {
                $found[$name][] = basename($path);
            }
        }

        foreach ($found as $name => $files) {
            $found[$name] = array_values(array_unique($files));
        }

        ksort($found);

        return $found;
    }

    /**
     * @return list<string>
     */
    private function seriesIn(string $yaml): array
    {
        $lines = preg_split('/\R/', $yaml) ?: [];
        $expressions = [];
        $inExpression = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*expr:\s*(.*)$/', $line, $match) === 1) {
                $expressions[] = $match[1];
                $inExpression = in_array(trim($match[1]), ['>-', '>', '|', '|-'], strict: true);

                continue;
            }

            if (! $inExpression) {
                continue;
            }

            // A folded expression runs until the next key at the same or lower
            // indentation; everything under it is still the expression.
            if (preg_match('/^\s*[a-z_]+:/', $line) === 1) {
                $inExpression = false;

                continue;
            }

            $expressions[] = $line;
        }

        /*
         * `[a-z0-9_]+` after the prefix, so a `{__name__=~"lynomia_.*"}`
         * matcher does not register the bare prefix as a series nobody
         * produces. A regex matcher selects whatever exists; it is not a claim
         * that a particular name does.
         */
        preg_match_all('/lynomia_[a-z0-9]+[a-z0-9_]*/', implode("\n", $expressions), $names);

        $unique = array_values(array_unique($names[0]));
        sort($unique);

        return $unique;
    }

    /**
     * @return list<string>
     */
    private function seriesTheApplicationExports(): array
    {
        // Deliberately no $this->app->register(...). See the class docblock.
        return array_values(array_unique(array_map(
            static fn (Metric $metric): string => $metric->name,
            app(MetricsRegistry::class)->collect(),
        )));
    }
}
