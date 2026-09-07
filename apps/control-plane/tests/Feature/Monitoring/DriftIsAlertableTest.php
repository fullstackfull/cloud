<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Monitoring\Application\Collectors\ProvisioningCollector;
use Lynomia\Modules\Monitoring\Infrastructure\Formatters\PrometheusTextFormatter;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Drift has to reach somebody who is not already looking at the drift screen.
 *
 * RecordDrift says the platform "records, alerts, and waits for a person". The
 * recording was built and the alerting was not: drift accumulated in a table
 * with no series behind it, so the only way to learn that a customer's machine
 * had gone missing at the hypervisor was for an operator to go and look.
 */
final class DriftIsAlertableTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_critical_drift_appears_in_the_exposition(): void
    {
        ResourceDrift::factory()->create([
            'kind' => DriftKind::MissingAtProvider,
            'severity' => DriftSeverity::Critical,
            'status' => DriftStatus::Open,
        ]);

        $this->assertStringContainsString(
            'lynomia_resource_drift_open{kind="missing_at_provider",severity="critical"} 1',
            $this->scrape(),
        );
    }

    #[Test]
    public function every_combination_is_published_even_at_zero(): void
    {
        /*
         * A series that only appears once there is drift is a series nobody
         * can write an alert rule against in advance — which is to say, before
         * the incident.
         */
        $exposition = $this->scrape();

        foreach (DriftKind::cases() as $kind) {
            foreach (DriftSeverity::cases() as $severity) {
                $this->assertStringContainsString(
                    sprintf('kind="%s",severity="%s"} 0', $kind->value, $severity->value),
                    $exposition,
                );
            }
        }
    }

    #[Test]
    public function a_resolved_drift_stops_being_reported(): void
    {
        // What an alert needs is what is still true. A resolved row that kept
        // its value would hold a rule open for ever after the fix.
        $drift = ResourceDrift::factory()->create([
            'kind' => DriftKind::OrphanAtProvider,
            'severity' => DriftSeverity::Warning,
            'status' => DriftStatus::Open,
        ]);

        $this->assertStringContainsString(
            'kind="orphan_at_provider",severity="warning"} 1',
            $this->scrape(),
        );

        $drift->forceFill(['status' => DriftStatus::Resolved])->save();

        $this->assertStringContainsString(
            'kind="orphan_at_provider",severity="warning"} 0',
            $this->scrape(),
        );
    }

    #[Test]
    public function an_acknowledged_drift_is_still_reported(): void
    {
        // Acknowledged means a person has seen it and it is still true. An
        // alert that cleared on acknowledgement would let somebody silence a
        // missing machine by clicking a button.
        ResourceDrift::factory()->create([
            'kind' => DriftKind::MissingAtProvider,
            'severity' => DriftSeverity::Critical,
            'status' => DriftStatus::Acknowledged,
        ]);

        $this->assertStringContainsString(
            'kind="missing_at_provider",severity="critical"} 1',
            $this->scrape(),
        );
    }

    #[Test]
    public function the_series_count_does_not_grow_with_the_drift(): void
    {
        /*
         * A metric labelled by service or provider reference would add one
         * series per missing machine — a cardinality explosion triggered by
         * precisely the incident it exists to report.
         */
        $before = $this->seriesCount();

        ResourceDrift::factory()->count(50)->create([
            'kind' => DriftKind::MissingAtProvider,
            'severity' => DriftSeverity::Critical,
            'status' => DriftStatus::Open,
        ]);

        $this->assertSame($before, $this->seriesCount());
    }

    private function seriesCount(): int
    {
        foreach (app(ProvisioningCollector::class)->collect() as $metric) {
            if ($metric->name === 'lynomia_resource_drift_open') {
                return count($metric->samples);
            }
        }

        $this->fail('The drift metric is not published at all.');
    }

    private function scrape(): string
    {
        return app(PrometheusTextFormatter::class)->render(app(ProvisioningCollector::class)->collect());
    }
}
