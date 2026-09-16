<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Preflight\Checks;

use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckCategory;
use Lynomia\Modules\Infrastructure\Domain\Preflight\EvidenceClass;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;
use Lynomia\Modules\Monitoring\Domain\Services\MetricsRegistry;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Throwable;

/**
 * The two dependencies every product quietly has, and neither of which is
 * visible from the product's own configuration.
 *
 * ===========================================================================
 * BACKUP
 * ===========================================================================
 *
 * A platform that cannot restore is a platform whose worst day has not
 * happened yet. Four separate things have to be true and each fails
 * differently:
 *
 *   1. a backup provider is configured and in service,
 *   2. its identity is known — which the provider chain establishes,
 *   3. the series the backup alerts read are being produced,
 *   4. verification state is observable at all.
 *
 * The third is Gap 1's finding, and the reason it is checked here: for two
 * phases `backups.yml` held six alerts and nothing produced the series any of
 * them read. Prometheus does not complain about that. A rule whose metric is
 * absent evaluates to an empty vector, which is indistinguishable — on a
 * dashboard, in an alert list, and to anybody reviewing the file — from a rule
 * that is passing. So the producer is asked directly.
 *
 * The fourth is deliberately reported as observability rather than as safety.
 * A verified backup is one somebody has restored; this platform can see
 * whether the backup server checked its own chunks, and it says exactly that
 * much. No preflight in either mode claims a restore has been verified.
 *
 * ===========================================================================
 * MONITORING
 * ===========================================================================
 *
 * Asked of the registry that actually serves `/metrics`, not of the alert
 * files. A collector that exists but was never registered produces nothing,
 * and the file scan in the architecture suite cannot see that — it is a
 * runtime fact, so it is checked at runtime.
 */
final readonly class DependencyChain
{
    /**
     * The metric families whose absence makes an alert silent rather than
     * failing, taken from the alert rules Gap 1 wired up.
     *
     * @var list<string>
     */
    private const array CRITICAL_SERIES = [
        'lynomia_backup_collector_last_run_timestamp_seconds',
        'lynomia_backup_last_success_timestamp_seconds',
        'lynomia_backup_verify_last_run_timestamp_seconds',
        'lynomia_backup_unverified_snapshots',
    ];

    public function __construct(private MetricsRegistry $metrics) {}

    /**
     * @return list<PreflightFinding>
     */
    public function inspect(): array
    {
        return [...$this->backups(), ...$this->monitoring()];
    }

    /**
     * @return list<PreflightFinding>
     */
    private function backups(): array
    {
        $findings = [];

        $providers = ProviderInstance::query()
            ->where('category', ProviderCategory::Backup->value)
            ->get();

        $inService = $providers->filter(
            static fn (ProviderInstance $provider): bool => $provider->state === ProviderState::Enabled
                || $provider->state === ProviderState::Ready,
        );

        $findings[] = $inService->isEmpty()
            ? PreflightFinding::blocked(
                'dependency.backup_provider',
                CheckCategory::Backup,
                'backups',
                $providers->isEmpty()
                    ? 'No backup provider is configured, so nothing can be archived and nothing can be restored.'
                    : sprintf('%d backup provider(s) are configured and none is in service.', $providers->count()),
                BlockerReason::Configuration,
                'Configure a backup provider and bring it into service. Its own identity and capabilities are checked by the provider chain.',
            )
            : PreflightFinding::pass(
                'dependency.backup_provider',
                CheckCategory::Backup,
                'backups',
                sprintf('%d backup provider(s) in service.', $inService->count()),
                EvidenceClass::Configuration,
            );

        // ---- is anything producing what the alerts read? --------------------
        $produced = $this->producedSeries();

        if ($produced === null) {
            $findings[] = PreflightFinding::notTested(
                'dependency.backup_metrics',
                CheckCategory::Backup,
                'backups',
                'Not established: the metrics registry could not be collected from.',
            );

            return $findings;
        }

        $absent = array_values(array_diff(self::CRITICAL_SERIES, $produced));

        $findings[] = $absent === []
            ? PreflightFinding::pass(
                'dependency.backup_metrics',
                CheckCategory::Backup,
                'backups',
                sprintf('All %d series the backup alerts read are being produced.', count(self::CRITICAL_SERIES)),
                EvidenceClass::Configuration,
            )
            : PreflightFinding::fail(
                'dependency.backup_metrics',
                CheckCategory::Backup,
                'backups',
                sprintf(
                    '%d series the backup alerts read are produced by nothing: %s. An alert over an absent series does not fail — it is silent, which reads exactly like passing.',
                    count($absent),
                    implode(', ', $absent),
                ),
                'Register the collector that produces these series, or delete the alerts that read them.',
            );

        // ---- can verification be observed at all? ---------------------------
        $findings[] = $this->verificationObservability();

        return $findings;
    }

    /**
     * Whether the platform can see verification state, said carefully.
     *
     * Three states, and the middle one is the one that matters: snapshots
     * exist and none of them has ever been verified. That is the failure that
     * looks exactly like success right up until somebody needs a restore, and
     * `verified` is nullable in the schema precisely so that "not yet checked"
     * and "checked and bad" stay different facts.
     */
    private function verificationObservability(): PreflightFinding
    {
        $succeeded = Backup::query()->where('state', BackupState::Succeeded->value)->count();

        if ($succeeded === 0) {
            return PreflightFinding::notApplicable(
                'dependency.backup_verification',
                CheckCategory::Backup,
                'backups',
                'No successful backup exists yet, so there is no verification state to observe.',
            );
        }

        $unverified = Backup::query()
            ->where('state', BackupState::Succeeded->value)
            ->whereNull('verified')
            ->count();

        if ($unverified === $succeeded) {
            return PreflightFinding::warning(
                'dependency.backup_verification',
                CheckCategory::Backup,
                'backups',
                sprintf(
                    'All %d successful snapshot(s) are unverified: they have a size, a task id and a green row, and nobody has checked that their chunks match their checksums.',
                    $succeeded,
                ),
                'Configure verification on the backup server and let it run. This platform can read the verdict and cannot start one.',
            );
        }

        return PreflightFinding::pass(
            'dependency.backup_verification',
            CheckCategory::Backup,
            'backups',
            sprintf(
                'Verification state is observable: %d of %d successful snapshot(s) carry a verdict. A verdict is not a restore — no restore has been performed by this preflight.',
                $succeeded - $unverified,
                $succeeded,
            ),
            EvidenceClass::Configuration,
        );
    }

    /**
     * @return list<PreflightFinding>
     */
    private function monitoring(): array
    {
        $produced = $this->producedSeries();

        if ($produced === null) {
            return [PreflightFinding::fail(
                'dependency.monitoring',
                CheckCategory::Monitoring,
                'monitoring',
                'The metrics registry could not be collected from, so this deployment exports nothing for anything to alert on.',
                'Investigate the metrics endpoint. A platform nobody is watching is a platform whose outages customers report.',
            )];
        }

        if ($produced === []) {
            return [PreflightFinding::fail(
                'dependency.monitoring',
                CheckCategory::Monitoring,
                'monitoring',
                'The metrics registry produced no series at all.',
                'Register the metric collectors with the registry.',
            )];
        }

        return [PreflightFinding::pass(
            'dependency.monitoring',
            CheckCategory::Monitoring,
            'monitoring',
            sprintf('The metrics registry produces %d series famil(ies).', count($produced)),
            EvidenceClass::Configuration,
        )];
    }

    /**
     * The metric names this deployment actually exports, or null if the
     * registry could not be asked.
     *
     * Asked of the registry rather than of the source tree: a collector that
     * exists in a file and was never registered produces nothing, and that is
     * a runtime fact a file scan cannot see. The architecture suite checks the
     * source; this checks the running application.
     *
     * @return list<string>|null
     */
    private function producedSeries(): ?array
    {
        try {
            return array_map(
                static fn (Metric $metric): string => $metric->name,
                $this->metrics->collect(),
            );
        } catch (Throwable) {
            /*
             * Swallowed on purpose, and the null says so. A preflight whose
             * monitoring check threw would take the whole run down over the
             * thing that watches the run.
             */
            return null;
        }
    }
}
