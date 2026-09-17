<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Preflight\InfrastructurePreflightService;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;
use Lynomia\Modules\Infrastructure\Domain\Preflight\EvidenceClass;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightReport;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightRequest;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightScope;
use Lynomia\Modules\Infrastructure\Domain\Preflight\VerificationLevel;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProviderFacts;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductReadinessEvaluator;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\Providers\Application\Actions\RegisterProvider;
use Lynomia\Modules\Providers\Application\Services\ProbeProvider;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ControlledDriver;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Domain\Exceptions\NoSuchTester;
use Lynomia\Modules\Providers\Domain\Exceptions\ProviderRefused;
use Lynomia\Modules\Providers\Infrastructure\ConnectionTesterFactory;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Production isolation, for every controlled driver there is and will be.
 *
 * ===========================================================================
 * WHY THIS IS PARAMETERISED AND NOT WRITTEN OUT
 * ===========================================================================
 *
 * Because the failure mode is a tenth controlled driver.
 *
 * The guards in this platform are good: registration refuses a controlled
 * driver in production, every simulator refuses to be constructed there, the
 * fake tester refuses both the deployment and the row, the readiness engine
 * refuses to count a rehearsal towards a sale, and the preflight refuses to
 * dial a real provider in simulation or to treat a fake as evidence in real
 * mode. Each of those was written when there were two controlled drivers, and
 * a test that named `fake` and `fake_bmc` would keep passing for ever while a
 * driver added next year went unguarded.
 *
 * So every assertion below runs once per case of
 * {@see ControlledDriver}. Adding a driver adds its guard coverage; there is
 * no list to remember to update.
 */
final class NoControlledDriverSurvivesProductionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{ControlledDriver}>
     */
    public static function controlledDrivers(): iterable
    {
        foreach (ControlledDriver::cases() as $driver) {
            yield $driver->value => [$driver];
        }
    }

    #[Test]
    #[DataProvider('controlledDrivers')]
    public function registration_refuses_a_controlled_driver_on_a_production_installation(ControlledDriver $driver): void
    {
        /*
         * Checked against the running installation rather than the row's own
         * environment, and the difference matters: this refuses a rehearsal
         * driver on the production installation even when the row claims to be
         * for staging. The row is data somebody supplied; the installation is
         * a fact.
         */
        app()->detectEnvironment(static fn (): string => 'production');

        /*
         * The message is asserted and not only the exception class, and the
         * reason is a deliberate breakage that removed this very guard: five
         * of the nine drivers still raised ProviderRefused, because a provider
         * that runs on hardware we manage is refused first for naming no
         * machine, and the endpoint policy refuses a `fake://` address in
         * production too. Both are correct controls and neither is this one,
         * so a gate that accepted any refusal would have gone green over the
         * removal of the guard it exists for.
         */
        $this->expectException(ProviderRefused::class);
        $this->expectExceptionMessage('must never be registered in production');

        app(RegisterProvider::class)->execute(
            name: 'controlled-'.$driver->value,
            driver: $driver->value,
            category: $driver->category(),
            environment: DeploymentEnvironment::Staging,
            operator: User::factory()->create(),
            endpoint: 'fake://controlled-'.str_replace('_', '-', $driver->value),
        );
    }

    #[Test]
    #[DataProvider('controlledDrivers')]
    public function nothing_can_test_a_production_row_through_a_controlled_driver(ControlledDriver $driver): void
    {
        // The guard the deployment-level controls cannot see: a production row
        // tested from a staging deployment. That row is what the readiness
        // engine consults before a product goes on sale.
        $this->expectException(NoSuchTester::class);

        app(ProbeProvider::class)->testerFor($driver->value, DeploymentEnvironment::Production);
    }

    #[Test]
    #[DataProvider('controlledDrivers')]
    public function no_controlled_tester_is_even_registered_on_a_production_installation(ControlledDriver $driver): void
    {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->assertFalse(
            app(ConnectionTesterFactory::class)->handles($driver->value),
            sprintf('%s has a tester registered in production, so something there could answer for it.', $driver->value),
        );
    }

    #[Test]
    #[DataProvider('controlledDrivers')]
    public function a_rehearsal_can_never_make_a_product_sellable(ControlledDriver $driver): void
    {
        /*
         * Every capability supported, every state enabled, readiness at its
         * ceiling — the most flattering row a controlled provider could
         * possibly have — and the product still may not be sold. Simulation
         * adapts to readiness; readiness does not adapt to simulation.
         */
        $product = match ($driver) {
            ControlledDriver::Dns => Product::Dns,
            ControlledDriver::Compute => Product::Vps,
            ControlledDriver::Bmc => Product::Dedicated,
            ControlledDriver::Hosting => Product::SharedHosting,
            ControlledDriver::WordPress => Product::WordPress,
            ControlledDriver::Backup => Product::Backups,
            ControlledDriver::Registrar => Product::Domains,
            ControlledDriver::Payment, ControlledDriver::ReverseDns => Product::Vps,
        };

        $verdict = (new ProductReadinessEvaluator)->evaluate(
            $product,
            (new ProductRequirements)->for($product),
            array_map(
                fn (ControlledDriver $each): ProviderFacts => $this->flattering($each),
                ControlledDriver::cases(),
            ),
            [],
        );

        $this->assertNotSame(ProductReadinessState::ReadyToSell, $verdict->state);
        $this->assertNotSame(ProductReadinessState::ReadyForProduction, $verdict->state);
    }

    #[Test]
    public function simulation_resolves_a_controlled_provider_and_read_only_real_refuses_to_believe_it(): void
    {
        /*
         * The mode isolation pair, in one test so the two halves cannot drift.
         *
         * SIMULATION may use a controlled driver: that is what the mode is
         * for, and the evidence it produces is labelled as simulation.
         *
         * READ_ONLY_REAL may not: it is the mode whose results can support a
         * REAL_ claim, and a fake answering inside it would be a fake wearing
         * that label.
         */
        putenv('LYNOMIA_TEST_CONTROLLED_MODE_SECRET=controlled-simulation-placeholder');

        $provider = $this->controlledRow(ControlledDriver::Dns);

        $simulation = $this->preflight(PreflightMode::Simulation, $provider);

        $identity = $this->finding($simulation, 'provider.identity');

        $this->assertSame(CheckStatus::Pass, $identity->status);
        $this->assertSame(EvidenceClass::Simulation, $identity->evidence);
        $this->assertSame(VerificationLevel::RuntimeVerified, $identity->verified);
        $this->assertSame([], $simulation->realVerificationClaims());

        $real = $this->finding($this->preflight(PreflightMode::ReadOnlyReal, $provider), 'provider.identity');

        $this->assertSame(CheckStatus::Warning, $real->status);
        $this->assertSame(EvidenceClass::Simulation, $real->evidence);
        $this->assertNull($real->verified);

        putenv('LYNOMIA_TEST_CONTROLLED_MODE_SECRET');
    }

    #[Test]
    public function a_production_row_on_a_controlled_driver_is_blocked_in_both_modes(): void
    {
        $provider = $this->controlledRow(ControlledDriver::Dns, DeploymentEnvironment::Production);

        foreach ([PreflightMode::Simulation, PreflightMode::ReadOnlyReal] as $mode) {
            $report = $this->preflight($mode, $provider);
            $identity = $this->finding($report, 'provider.identity');

            /*
             * Never a pass, in either mode, and the report is blocked as a
             * whole. Which check blocks first is deliberately not asserted:
             * a production row in a non-production installation is refused
             * before its identity is even reached, and a report that started
             * failing one check earlier would still be correct.
             */
            $this->assertNotSame(CheckStatus::Pass, $identity->status, sprintf(
                'A production row answered by a controlled driver passed its identity check in %s mode.',
                $mode->value,
            ));

            $this->assertFalse($report->passed(), sprintf(
                'A production row answered by a controlled driver produced a passing %s report.',
                $mode->value,
            ));

            $this->assertNotSame([], $report->blockers(), sprintf(
                'A production row answered by a controlled driver produced a %s report with no blocker.',
                $mode->value,
            ));

            $this->assertSame([], $report->realVerificationClaims());
        }
    }

    private function preflight(PreflightMode $mode, ProviderInstance $provider): PreflightReport
    {
        return app(InfrastructurePreflightService::class)->run(
            new PreflightRequest($mode, PreflightScope::Provider, (string) $provider->getKey()),
        );
    }

    private function finding(PreflightReport $report, string $id): object
    {
        foreach ($report->findings as $finding) {
            if ($finding->id === $id) {
                return $finding;
            }
        }

        $this->fail(sprintf('The report carries no %s finding.', $id));
    }

    private function controlledRow(
        ControlledDriver $driver,
        DeploymentEnvironment $environment = DeploymentEnvironment::Development,
    ): ProviderInstance {
        $credential = CredentialReference::query()->create([
            'name' => 'controlled-mode-'.$driver->value,
            'purpose' => 'Rehearsal only. Nothing behind a controlled driver reads a secret.',
            'environment' => $environment->value,
            'backend' => 'controller_environment',
            'backend_reference' => 'LYNOMIA_TEST_CONTROLLED_MODE_SECRET',
            'state' => 'configured',
        ]);

        return ProviderInstance::query()->create([
            'name' => 'controlled-mode-'.$driver->value,
            'category' => $driver->category()->value,
            'driver' => $driver->value,
            'environment' => $environment->value,
            'state' => ProviderState::Draft->value,
            'endpoint' => 'fake://controlled-'.str_replace('_', '-', $driver->value),
            'credential_reference_id' => $credential->getKey(),
        ]);
    }

    private function flattering(ControlledDriver $driver): ProviderFacts
    {
        $capabilities = [];

        foreach ($driver->category()->capabilities() as $capability) {
            $capabilities[$capability] = CapabilityState::Supported;
        }

        return new ProviderFacts(
            id: 'flattering-'.$driver->value,
            name: 'flattering-'.$driver->value,
            category: $driver->category(),
            driver: $driver->value,
            controlled: true,
            environment: DeploymentEnvironment::Production,
            state: ProviderState::Enabled,
            readiness: ReadinessState::ReadyForProduction,
            blocker: null,
            capabilities: $capabilities,
        );
    }
}
