<?php

declare(strict_types=1);

namespace Tests\Feature\Simulation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProductVerdict;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProviderFacts;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\RequirementVerdict;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductReadinessEvaluator;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\Providers\Application\Actions\TestConnection;
use Lynomia\Modules\Providers\Domain\Enums\ControlledDriver;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\CredentialReference;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A simulator answers the capability question honestly, or not at all.
 *
 * ===========================================================================
 * WHY THIS IS THE GATE THAT MATTERS MOST
 * ===========================================================================
 *
 * Capability rows are read by one engine, and that engine decides whether a
 * product may be offered for sale. Everything else a fake gets wrong costs a
 * red build; this costs a customer buying something the platform cannot
 * deliver.
 *
 * Until Gap 6 the fake tester reported every capability its category asked
 * about as Supported, and for the two controlled drivers that existed that was
 * true — the DNS simulator really does implement all four DNS operations. The
 * moment a controlled driver was catalogued for a category whose questions
 * outrun its contract, the same code became a lie: a controlled hypervisor
 * asked about GPU passthrough, a controlled WordPress toolkit asked whether it
 * can uninstall a site, a controlled reverse-DNS account asked whether it can
 * clear a PTR. Nothing in this repository can do any of those three, and the
 * simulator now says so.
 *
 * ===========================================================================
 * THE TWO HALVES
 * ===========================================================================
 *
 * The negative half: a capability the simulator does not offer is recorded as
 * unsupported, and the product that requires it stays unsellable with that as
 * its reason.
 *
 * The positive half, in the same file because a negative gate alone can pass
 * by refusing everything: a capability the simulator does offer is recorded as
 * supported and does satisfy its requirement.
 */
final class AControlledDriverSaysOnlyWhatItCanDoTest extends TestCase
{
    use RefreshDatabase;

    private const string VARIABLE = 'LYNOMIA_TEST_CONTROLLED_CAPABILITY_SECRET';

    protected function tearDown(): void
    {
        putenv(self::VARIABLE);

        parent::tearDown();
    }

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
    public function a_connection_test_records_exactly_what_the_simulator_offers(ControlledDriver $driver): void
    {
        $provider = $this->rehearsalProvider($driver);

        $record = app(TestConnection::class)->forProvider($provider);

        $this->assertTrue($record->result->usable(), sprintf(
            'The %s rehearsal did not reach a usable state, so nothing below is a statement about capabilities.',
            $driver->value,
        ));

        $recorded = [];

        foreach (ProviderCapability::query()->where('provider_instance_id', $provider->getKey())->get() as $row) {
            $recorded[$row->capability] = $row->state;
        }

        foreach ($driver->category()->capabilities() as $capability) {
            $this->assertArrayHasKey($capability, $recorded, sprintf(
                'The %s category asks about %s and the connection test recorded no answer for it.',
                $driver->category()->value,
                $capability,
            ));

            $this->assertSame(
                $driver->stateOf($capability),
                $recorded[$capability],
                sprintf('%s recorded the wrong answer for %s.', $driver->value, $capability),
            );
        }
    }

    #[Test]
    #[DataProvider('controlledDrivers')]
    public function nothing_a_simulator_cannot_do_is_left_without_a_reason(ControlledDriver $driver): void
    {
        /*
         * Asserted for every driver, including the ones that offer everything
         * their category asks about: a driver whose supported set and category
         * set had drifted apart would otherwise pass this test by having
         * nothing to say.
         */
        $answered = [...$driver->supported(), ...array_keys($driver->unsupported())];
        $asked = $driver->category()->capabilities();

        sort($answered);
        sort($asked);

        $this->assertSame(
            $asked,
            $answered,
            sprintf('%s answers a different set of capabilities from the one its category asks about.', $driver->value),
        );

        foreach ($driver->unsupported() as $capability => $reason) {
            $this->assertContains(
                $capability,
                $driver->category()->capabilities(),
                sprintf('%s declares %s unsupported and its category never asks about it.', $driver->value, $capability),
            );

            $this->assertGreaterThan(120, mb_strlen($reason), sprintf(
                'The reason %s cannot do %s is too short to be a reason. It has to name the contract that is '
                .'missing, so the next person can tell whether it still is.',
                $driver->value,
                $capability,
            ));
        }
    }

    #[Test]
    public function a_capability_no_contract_models_keeps_its_product_unsellable(): void
    {
        /*
         * The negative half, on the three capabilities this platform asks
         * about and no interface in the repository answers:
         *
         *   compute.gpu_passthrough        — GPU compute
         *   wordpress_installer.uninstall  — WordPress
         *   wordpress_installer.ssl        — WordPress
         *   reverse_dns.clear_ptr          — VPS and dedicated
         *
         * Each one keeps a product not-ready, and that is the correct
         * outcome: simulation adapts to readiness, and readiness does not
         * adapt to simulation.
         */
        $verdict = $this->evaluate(Product::GpuCompute, [
            ControlledDriver::Compute,
            ControlledDriver::ReverseDns,
            ControlledDriver::Payment,
        ]);

        $this->assertSame(ProductReadinessState::NotReady, $verdict->state);
        $this->assertNotNull($verdict->blocker);

        $unmet = array_values(array_filter(
            $verdict->requirements,
            static fn (RequirementVerdict $requirement): bool => $requirement->blocker !== null,
        ));

        $this->assertNotSame([], $unmet, 'The product is not ready and no requirement says why.');

        // WordPress fails on the same shape for a different pair of
        // capabilities, and the reverse-DNS one blocks the two products that
        // sell an address with a name on it.
        $wordpress = $this->evaluate(Product::WordPress, [
            ControlledDriver::WordPress,
            ControlledDriver::Payment,
        ]);

        $this->assertSame(ProductReadinessState::NotReady, $wordpress->state);
    }

    #[Test]
    public function a_capability_the_simulator_does_offer_satisfies_its_requirement(): void
    {
        /*
         * The positive twin. Without it the assertion above would pass for a
         * simulator that refused every capability it was asked about, which is
         * the other way to make a capability gate meaningless.
         *
         * DNS is the family whose contract answers every question its category
         * asks, so its product's only remaining blockers are the ones every
         * product has — and none of them is a capability.
         */
        $verdict = $this->evaluate(Product::Dns, [ControlledDriver::Dns, ControlledDriver::Payment]);

        $dns = $this->requirementFor($verdict, ProviderCategory::Dns);

        /*
         * Satisfied as far as a rehearsal can take it, and no further.
         *
         * The requirement is still not production-ready, and it must not be:
         * the readiness engine's own ceiling on controlled drivers says
         * "enough to rehearse, never to validate or sell", and nothing in this
         * gap may defeat that. What the positive twin proves is the
         * difference between the two reasons a requirement can fall short —
         * a capability the platform does not have, and a provider that is a
         * rehearsal. The first is NotReady; this is not.
         */
        $this->assertNotSame(
            ProductReadinessState::NotReady,
            $dns->satisfiedUpTo,
            sprintf(
                'The DNS simulator implements every operation its category asks about and the requirement reached '
                .'nothing at all (%s) — which would mean the capability gate refuses everything and proves nothing.',
                $dns->detail,
            ),
        );

        // And the capability shortfall above really is the reason that one
        // stopped at nothing, rather than both stopping for the same reason.
        $gpu = $this->requirementFor(
            $this->evaluate(Product::GpuCompute, [ControlledDriver::Compute, ControlledDriver::ReverseDns, ControlledDriver::Payment]),
            ProviderCategory::Compute,
        );

        $this->assertSame(ProductReadinessState::NotReady, $gpu->satisfiedUpTo);
    }

    private function requirementFor(ProductVerdict $verdict, ProviderCategory $category): RequirementVerdict
    {
        foreach ($verdict->requirements as $requirement) {
            if ($requirement->requirement->category === $category) {
                return $requirement;
            }
        }

        $this->fail(sprintf('%s has no %s requirement.', $verdict->product->value, $category->value));
    }

    /**
     * The verdict for one product, judged against controlled providers whose
     * capabilities are exactly what their simulators offer.
     *
     * @param  list<ControlledDriver>  $drivers
     */
    private function evaluate(Product $product, array $drivers): ProductVerdict
    {
        $requirements = new ProductRequirements;

        return (new ProductReadinessEvaluator)->evaluate(
            $product,
            $requirements->for($product),
            array_map(fn (ControlledDriver $driver): ProviderFacts => $this->facts($driver), $drivers),
            [],
        );
    }

    /**
     * A controlled provider whose capabilities have been established, through
     * the same action an operator's "test connection" button runs.
     */
    private function rehearsalProvider(ControlledDriver $driver): ProviderInstance
    {
        /*
         * A placeholder, and deliberately not a secret. The value is never
         * read by anything behind a controlled driver — no simulator resolves
         * it — and it exists only because the credential architecture is part
         * of what a rehearsal has to exercise: a provider with no credential
         * reference is blocked on credentials, which is a path of its own.
         */
        putenv(self::VARIABLE.'=controlled-simulation-placeholder');

        $credential = CredentialReference::query()->create([
            'name' => 'controlled-'.$driver->value,
            'purpose' => 'Rehearsal only. Nothing behind a controlled driver reads a secret.',
            'environment' => DeploymentEnvironment::Development->value,
            'backend' => 'controller_environment',
            'backend_reference' => self::VARIABLE,
            'state' => 'configured',
        ]);

        return ProviderInstance::query()->create([
            'name' => 'controlled-'.$driver->value,
            'category' => $driver->category()->value,
            'driver' => $driver->value,
            'environment' => DeploymentEnvironment::Development->value,
            'state' => ProviderState::Draft->value,
            // The endpoint policy admits `fake://` plus lowercase letters,
            // digits and hyphens, and nothing else — so the driver's own
            // underscores cannot go in an address.
            'endpoint' => 'fake://controlled-'.str_replace('_', '-', $driver->value),
            'credential_reference_id' => $credential->getKey(),
        ]);
    }

    private function facts(ControlledDriver $driver): ProviderFacts
    {
        $capabilities = [];

        foreach ($driver->category()->capabilities() as $capability) {
            $capabilities[$capability] = $driver->stateOf($capability);
        }

        return new ProviderFacts(
            id: 'controlled-'.$driver->value,
            name: 'controlled-'.$driver->value,
            category: $driver->category(),
            driver: $driver->value,
            controlled: true,
            environment: DeploymentEnvironment::Development,
            state: ProviderState::Enabled,
            readiness: ReadinessState::ReadyForProduction,
            blocker: null,
            capabilities: $capabilities,
        );
    }
}
