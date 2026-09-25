<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Lynomia\Modules\Monitoring\Domain\Services\MetricsRegistry;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The application itself must export metrics, not just be able to.
 *
 * ===========================================================================
 * THE FAILURE THIS EXISTS TO PREVENT, WHICH ALREADY HAPPENED
 * ===========================================================================
 *
 * `MonitoringServiceProvider` — which registers all sixteen collectors with
 * the registry that serves `/metrics` — was not in `bootstrap/providers.php`.
 * The consequence was total and silent: a booted application resolved
 * MetricsRegistry to a bare instance with **zero** collectors and exported
 * only the two families the registry uses to describe itself. Every alert rule
 * in `infrastructure/monitoring` read a series that nothing produced, and a
 * rule over an absent series does not fail — it evaluates to an empty vector,
 * which on a dashboard, in an alert list, and to anybody reviewing the file is
 * indistinguishable from a rule that is passing.
 *
 * Gap 1 wrote the backup collector and proved it produces the six series the
 * backup alerts read. That was true and it was not enough: the collector was
 * correct, was registered with the provider, and the provider was never
 * loaded.
 *
 * ===========================================================================
 * WHY THE EXISTING TESTS COULD NOT SEE IT
 * ===========================================================================
 *
 * Every monitoring test calls `$this->app->register(MonitoringServiceProvider::class)`
 * in its own setup — reasonably, to isolate what it is measuring. So they were
 * all measuring a registry the application never had. The architecture gate
 * beside this one checked that the provider's *source* names
 * `BackupCollector::class`, which it did.
 *
 * This test asks the application. It registers nothing, arranges nothing, and
 * resolves the registry exactly as a request would — so the thing it measures
 * is what a scrape would get.
 */
final class TheApplicationActuallyRegistersItsCollectorsTest extends TestCase
{
    /**
     * Series whose absence makes a critical alert silent rather than failing.
     *
     * One per alert family that pages. Not the whole export — that number
     * moves whenever a collector is added — but the ones where being wrong
     * costs a customer's data or a customer's money.
     *
     * @var list<string>
     */
    private const array MUST_BE_EXPORTED = [
        'lynomia_backup_collector_last_run_timestamp_seconds',
        'lynomia_backup_last_success_timestamp_seconds',
        'lynomia_backup_unverified_snapshots',
        'lynomia_backup_verify_last_run_timestamp_seconds',
        // The dedicated power path: a reset the platform may have sent and
        // cannot vouch for, and a claim whose worker is gone.
        'lynomia_dedicated_power_operation_total',
        'lynomia_dedicated_power_claims_abandoned',
    ];

    #[Test]
    public function a_booted_application_exports_the_series_the_alerts_read(): void
    {
        // Deliberately no $this->app->register(...) here. That call is what
        // hid this for two phases.
        $exported = array_map(
            static fn (Metric $metric): string => $metric->name,
            app(MetricsRegistry::class)->collect(),
        );

        $missing = array_values(array_diff(self::MUST_BE_EXPORTED, $exported));

        $this->assertSame(
            [],
            $missing,
            "A booted application does not export these series:\n  ".implode("\n  ", $missing)
            ."\n\nThe collectors exist and something is not registering them — check that "
            .'MonitoringServiceProvider is in bootstrap/providers.php. An alert over an absent series is silent, '
            .'which reads exactly like passing.',
        );
    }

    #[Test]
    public function a_booted_application_registers_more_than_the_registrys_own_two_families(): void
    {
        /*
         * The shape of the original failure, pinned directly. Two families —
         * `lynomia_metrics_collect_duration_seconds` and
         * `lynomia_metrics_collector_up` — are what a registry with no
         * collectors produces, because they are how it describes itself. Two
         * is the number that means nothing is wired up.
         */
        $families = app(MetricsRegistry::class)->collect();

        $this->assertGreaterThan(
            2,
            count($families),
            'The application exports only the families the metrics registry uses to describe itself, which is what a '
            .'registry with zero collectors produces.',
        );
    }
}
