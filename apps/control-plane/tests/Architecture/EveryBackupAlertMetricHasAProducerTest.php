<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Monitoring\Application\Collectors\BackupCollector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * No alert may read a metric nobody writes.
 *
 * ===========================================================================
 * THE FAILURE THIS EXISTS TO PREVENT
 * ===========================================================================
 *
 * `backups.yml` held six alerts for two phases, and nothing produced the
 * series any of them read. That is the worst shape a monitoring failure can
 * take, because Prometheus does not complain about it. A rule whose metric is
 * absent evaluates to an empty vector, which is indistinguishable — on the
 * dashboard, in the alert list, and to anybody reviewing the file — from a
 * rule that is passing.
 *
 * Two of those six were the alerts that catch an *unverified* backup: the
 * failure that looks exactly like success right up until somebody needs a
 * restore.
 *
 * A test that only checked the collector emits six metrics would not have
 * caught it either, because the names could still drift apart. So this asserts
 * the two sets are equal **in both directions**:
 *
 *   - every `lynomia_backup_*` metric an alert reads has a producer, and
 *   - every `lynomia_backup_*` metric the producer emits is read by an alert
 *     or is listed below with a reason.
 *
 * The second direction matters as much as the first. A metric nobody alerts on
 * is not necessarily wrong, but it should be a decision somebody wrote down
 * rather than a leftover.
 */
final class EveryBackupAlertMetricHasAProducerTest extends TestCase
{
    /**
     * Backup metrics the platform exports that no alert in `backups.yml`
     * reads, each with the reason it is allowed to exist unalerted.
     *
     * These three predate this gate and are counters used for dashboards and
     * capacity questions, not for paging.
     */
    private const UNALERTED = [
        'lynomia_backup_deletion_total' => 'A counter of deletions by outcome. Dashboard and audit material; a deletion is a customer or retention decision rather than a fault, so there is nothing to page about.',
        'lynomia_backup_file_restores_total' => 'A counter of file-level restores. Useful for knowing whether the feature is used at all; a restore succeeding is not an alertable event.',
        'lynomia_backup_retention_total' => 'A counter of retention sweeps by outcome. The sweep failing is visible through the job-failure metrics that already page.',
    ];

    #[Test]
    public function every_backup_metric_an_alert_reads_has_something_that_writes_it(): void
    {
        $read = $this->metricsTheAlertsRead();
        $written = $this->metricsTheCollectorWrites();

        $this->assertNotSame([], $read, 'No lynomia_backup_* metric was found in the alert rules. Either the rules moved or this scanner is broken — and a scanner that finds nothing passes every assertion below for the wrong reason.');

        $orphans = array_values(array_diff($read, $written));

        $this->assertSame(
            [],
            $orphans,
            "These metrics are read by an alert in backups.yml and produced by nothing:\n  "
            .implode("\n  ", $orphans)
            ."\n\nAn alert over an absent series does not fail — it is silent, which reads exactly like passing. "
            .'Add the series to BackupCollector, or delete the alert.',
        );
    }

    #[Test]
    public function every_backup_metric_the_collector_writes_is_alerted_on_or_written_down(): void
    {
        $read = $this->metricsTheAlertsRead();
        $written = $this->metricsTheCollectorWrites();

        $unexplained = array_values(array_diff($written, $read, array_keys(self::UNALERTED)));

        $this->assertSame(
            [],
            $unexplained,
            "These metrics are produced and no alert reads them, and no reason is recorded:\n  "
            .implode("\n  ", $unexplained)
            ."\n\nEither alert on them, or add them to UNALERTED with the reason they exist.",
        );
    }

    #[Test]
    public function the_alert_that_watches_the_collector_is_itself_produced(): void
    {
        /*
         * Singled out because it is the one that makes the other five
         * trustworthy. `BackupCollectorStale` uses `absent()`, so it is the
         * only rule in the file that can fire when the producer dies — and if
         * its own series has no producer, the whole file is unguarded.
         */
        $this->assertContains(
            'lynomia_backup_collector_last_run_timestamp_seconds',
            $this->metricsTheCollectorWrites(),
            'The heartbeat the BackupCollectorStale alert reads is not produced. Without it, a dead collector looks identical to a platform where every backup is fine.',
        );
    }

    #[Test]
    public function a_created_backup_is_not_reported_as_a_verified_one(): void
    {
        /*
         * The product invariant behind the two critical alerts, asserted
         * against the collector's own help text rather than against a fixture,
         * because the distinction has to survive somebody rewriting the SQL.
         *
         * `verified` is nullable in the schema precisely so that "not yet
         * verified" and "verified and failed" are different states. If a
         * future change folded NULL into the pass bucket, the unverified count
         * would drop to zero and the platform would report perfect backup
         * health while knowing nothing about it.
         */
        $metrics = (new BackupCollector)->collect();

        $byName = [];

        foreach ($metrics as $metric) {
            $byName[$metric->name] = $metric->help;
        }

        $this->assertArrayHasKey('lynomia_backup_unverified_snapshots', $byName);

        $this->assertStringContainsString(
            'not known to restore',
            $byName['lynomia_backup_unverified_snapshots'],
            'The unverified-snapshot metric must say what it means: these are backups that look healthy and are not known to restore. If that sentence goes, check that the meaning did not go with it.',
        );

        $this->assertStringContainsString(
            'Emitted only where verification has actually run',
            $byName['lynomia_backup_verify_last_status'] ?? '',
            'The verification-status metric must say that it covers only groups where verification ran. '
            .'Without that, a reader would take the absence of a failure as a pass — which is the exact '
            .'confusion the two critical alerts exist to prevent.',
        );
    }

    /**
     * Metric names any rule in the backup alert file reads.
     *
     * A text scan rather than a YAML parse, and it says so: the expressions are
     * PromQL inside YAML strings, and what is wanted is every `lynomia_backup_*`
     * identifier mentioned anywhere in an `expr`, including inside `absent()`
     * and inside multi-line folded scalars.
     *
     * @return list<string>
     */
    private function metricsTheAlertsRead(): array
    {
        $path = dirname(__DIR__, 4).'/infrastructure/monitoring/prometheus/rules/backups.yml';

        $this->assertFileExists($path, 'The backup alert rules are not where this test expects them. If they moved, move this scanner with them rather than deleting the assertion.');

        $yaml = (string) file_get_contents($path);

        $lines = preg_split('/\R/', $yaml) ?: [];
        $expressions = [];
        $inExpression = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*expr:\s*(.*)$/', $line, $match) === 1) {
                $expressions[] = $match[1];
                $inExpression = trim($match[1]) === '>-' || trim($match[1]) === '>' || trim($match[1]) === '|';

                continue;
            }

            /*
             * A folded expression continues until the next key at the same or
             * lower indentation. Everything indented under it is still the
             * expression.
             */
            if ($inExpression) {
                if (preg_match('/^\s*[a-z_]+:/', $line) === 1) {
                    $inExpression = false;

                    continue;
                }

                $expressions[] = $line;
            }
        }

        preg_match_all('/lynomia_backup_[a-z0-9_]+/', implode("\n", $expressions), $found);

        $names = array_values(array_unique($found[0]));
        sort($names);

        return $names;
    }

    /**
     * Metric names the platform's collectors actually emit for backups.
     *
     * Read from the registry rather than from a hard-coded list, so that a
     * collector being removed from the service provider fails this test
     * instead of silently un-producing six series.
     *
     * @return list<string>
     */
    private function metricsTheCollectorWrites(): array
    {
        $directory = dirname(__DIR__, 2).'/src/Modules/Monitoring/Application/Collectors';

        $this->assertDirectoryExists($directory, 'The collectors are not where this test expects them.');

        $names = [];

        /*
         * Only names passed as the first argument to a Metric factory count.
         *
         * An earlier version of this scanner matched the identifier anywhere in
         * the file, and a deliberate breakage proved it useless: renaming an
         * emitted metric still passed, because the old name was still mentioned
         * in another metric's help text. A prose mention is not a producer.
         */
        foreach ((array) glob($directory.'/*.php') as $file) {
            preg_match_all(
                "/Metric::(?:gauge|counter|histogram)\(\s*'(lynomia_backup_[a-z0-9_]+)'/",
                (string) file_get_contents((string) $file),
                $found,
            );

            foreach ($found[1] as $name) {
                $names[] = $name;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * A collector that exists but is never registered produces nothing.
     *
     * The source scan above would be satisfied by a file nobody wires up, so
     * the registration is asserted separately rather than assumed. This is the
     * cheap half of the gate and it is the half that would have caught the
     * original failure a second time.
     */
    #[Test]
    public function the_backup_collector_is_registered_with_the_metrics_registry(): void
    {
        $provider = dirname(__DIR__, 2).'/src/Modules/Monitoring/Infrastructure/MonitoringServiceProvider.php';

        $this->assertFileExists($provider);

        $source = (string) file_get_contents($provider);

        $this->assertStringContainsString(
            'BackupCollector::class',
            $source,
            'BackupCollector is not registered with the MetricsRegistry, so none of its six series is ever scraped and every alert in backups.yml is blind again.',
        );
    }
}
