<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Domain\Enums\SafetyClass;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Monitoring\Application\Collectors\ControlCenterCollector;
use Lynomia\Modules\Monitoring\Domain\ValueObjects\Metric;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The control centre's numbers: every combination published at zero so a
 * rule can be written before the first row exists, the two series that page
 * counting what they claim to count, and the alert file naming only series
 * this collector actually emits.
 */
final class TheControlCenterIsObservableTest extends TestCase
{
    use RefreshDatabase;

    private const string RULES = __DIR__.'/../../../../../infrastructure/monitoring/prometheus/rules/control-center.yml';

    /**
     * @return array<string, float>
     */
    private function samples(): array
    {
        $flat = [];

        foreach (app(ControlCenterCollector::class)->collect() as $metric) {
            foreach ($metric->samples as $sample) {
                $labels = $sample->labels;
                ksort($labels);
                $flat[$metric->name.'{'.http_build_query($labels, '', ',').'}'] = $sample->value;
            }
        }

        return $flat;
    }

    #[Test]
    public function every_combination_is_published_at_zero_on_an_empty_estate(): void
    {
        $samples = $this->samples();

        $this->assertSame(0.0, $samples['lynomia_managed_servers{classification=do_not_touch,state=registered}']);
        $this->assertSame(0.0, $samples['lynomia_provider_readiness{category=dns,readiness=ready_for_production}']);
        $this->assertSame(0.0, $samples['lynomia_provider_state{state=enabled}']);
        $this->assertSame(0.0, $samples['lynomia_providers_enabled_not_ready{}']);
        $this->assertSame(0.0, $samples['lynomia_credentials{state=missing}']);
        $this->assertSame(0.0, $samples['lynomia_licences{state=expired}']);
        $this->assertSame(0.0, $samples['lynomia_deployments{kind=apply,state=indeterminate}']);

        // A product never assessed is on the bottom rung, and only there.
        $this->assertSame(1.0, $samples['lynomia_product_readiness{product=vps,state=not_ready}']);
        $this->assertSame(0.0, $samples['lynomia_product_readiness{product=vps,state=ready_to_sell}']);
    }

    #[Test]
    public function the_two_series_that_page_count_what_they_claim(): void
    {
        $server = ManagedServer::factory()->classified(SafetyClass::ConfigurationAllowed)->create();

        DeploymentJob::query()->create(['managed_server_id' => $server->getKey(), 'state' => DeploymentState::Indeterminate, 'kind' => 'apply', 'idempotency_key' => 'a', 'started_at' => CarbonImmutable::now(), 'finished_at' => CarbonImmutable::now()]);
        DeploymentJob::query()->create(['managed_server_id' => $server->getKey(), 'state' => DeploymentState::Completed, 'kind' => 'verify', 'idempotency_key' => 'b']);

        // Enabled and ready: not the alert's business. Enabled and no longer
        // ready: exactly its business.
        ProviderInstance::factory()->of(ProviderCategory::Dns)->enabled()->create();
        ProviderInstance::factory()->of(ProviderCategory::Registrar)->enabled()->create(['readiness' => ReadinessState::NotReady]);

        $samples = $this->samples();

        $this->assertSame(1.0, $samples['lynomia_deployments{kind=apply,state=indeterminate}']);
        $this->assertSame(1.0, $samples['lynomia_deployments{kind=verify,state=completed}']);
        $this->assertSame(1.0, $samples['lynomia_managed_servers{classification=configuration_allowed,state=registered}']);
        $this->assertSame(2.0, $samples['lynomia_provider_state{state=enabled}']);
        $this->assertSame(1.0, $samples['lynomia_providers_enabled_not_ready{}']);
    }

    #[Test]
    public function every_series_the_alert_rules_name_is_one_this_collector_emits(): void
    {
        $this->assertFileExists(self::RULES);

        preg_match_all('/lynomia_[a-z0-9_]+/', (string) file_get_contents(self::RULES), $matches);
        $named = array_values(array_unique($matches[0]));
        $this->assertNotEmpty($named);

        $emitted = array_map(static fn (Metric $m): string => $m->name, app(ControlCenterCollector::class)->collect());

        foreach ($named as $series) {
            $this->assertContains($series, $emitted, "control-center.yml alerts on {$series}, which nothing exports.");
        }
    }

    #[Test]
    public function no_series_names_a_machine_a_provider_or_a_person(): void
    {
        ManagedServer::factory()->classified(SafetyClass::DiscoveryOnly)->create(['name' => 'very-identifying-hostname']);
        ProviderInstance::factory()->of(ProviderCategory::Dns)->create(['name' => 'very-identifying-provider']);

        $rendered = implode("\n", array_keys($this->samples()));

        $this->assertStringNotContainsString('very-identifying', $rendered);
    }
}
