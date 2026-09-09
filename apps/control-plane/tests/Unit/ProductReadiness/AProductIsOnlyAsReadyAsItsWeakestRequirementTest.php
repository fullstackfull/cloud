<?php

declare(strict_types=1);

namespace Tests\Unit\ProductReadiness;

use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProductVerdict;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProviderFacts;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductReadinessEvaluator;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ladder, rung by rung, against stated facts and nothing else.
 *
 * The property under test throughout: a controlled provider can carry a
 * requirement to ready_for_test and no further, whatever environment it was
 * registered in and whatever state it is in. The three times this platform
 * shipped a capability that could not complete, a check that stopped at "the
 * configuration looks complete" would have passed every time.
 */
final class AProductIsOnlyAsReadyAsItsWeakestRequirementTest extends TestCase
{
    private ProductReadinessEvaluator $evaluator;

    private ProductRequirements $requirements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new ProductReadinessEvaluator;
        $this->requirements = new ProductRequirements;
    }

    /**
     * @param  list<string>|null  $supports  Defaults to everything the category asks about.
     */
    private function provider(
        ProviderCategory $category,
        string $driver = 'cloudflare',
        bool $controlled = false,
        DeploymentEnvironment $environment = DeploymentEnvironment::Production,
        ProviderState $state = ProviderState::Enabled,
        ReadinessState $readiness = ReadinessState::ReadyForProduction,
        ?BlockerReason $blocker = null,
        ?array $supports = null,
        string $name = '',
    ): ProviderFacts {
        $capabilities = [];

        foreach ($category->capabilities() as $capability) {
            $capabilities[$capability] = ($supports === null || in_array($capability, $supports, strict: true))
                ? CapabilityState::Supported
                : CapabilityState::Unsupported;
        }

        return new ProviderFacts(
            id: '01J'.strtoupper(substr(md5($name.$category->value.$driver), 0, 20)),
            name: $name !== '' ? $name : $category->value.'-'.$driver,
            category: $category,
            driver: $driver,
            controlled: $controlled,
            environment: $environment,
            state: $state,
            readiness: $readiness,
            blocker: $blocker,
            capabilities: $capabilities,
        );
    }

    /**
     * Payment and email, live and real: the shared requirements met, so a
     * test can look at one product's own requirement in isolation.
     *
     * @return list<ProviderFacts>
     */
    private function sharedMet(): array
    {
        return [
            $this->provider(ProviderCategory::Payment, 'stripe'),
            $this->provider(ProviderCategory::Email, 'smtp'),
        ];
    }

    private function evaluate(Product $product, array $providers, array $dependencies = []): ProductVerdict
    {
        return $this->evaluator->evaluate($product, $this->requirements->for($product), $providers, $dependencies);
    }

    #[Test]
    public function with_no_providers_at_all_nothing_is_ready_and_the_first_own_requirement_is_blamed(): void
    {
        $verdict = $this->evaluate(Product::Dns, []);

        $this->assertSame(ProductReadinessState::NotReady, $verdict->state);
        $this->assertSame(BlockerReason::Dependency, $verdict->blocker);
        $this->assertStringStartsWith('dns:', $verdict->detail);
        $this->assertStringContainsString('No dns provider is registered', $verdict->detail);
    }

    #[Test]
    public function a_controlled_provider_reaches_ready_for_test_and_never_higher(): void
    {
        $fake = $this->provider(ProviderCategory::Dns, 'fake', controlled: true, environment: DeploymentEnvironment::Staging, state: ProviderState::Ready);

        $verdict = $this->evaluate(Product::Dns, [...$this->sharedMet(), $fake]);

        $this->assertSame(ProductReadinessState::ReadyForTest, $verdict->state);
        $this->assertSame(BlockerReason::Configuration, $verdict->blocker);
        $this->assertStringContainsString('controlled fake driver', $verdict->detail);
    }

    #[Test]
    public function a_controlled_provider_enabled_in_production_still_only_counts_for_testing(): void
    {
        // Registration refuses this combination. The evaluator must refuse it
        // too, because a row can arrive by any road and the ladder must not
        // trust the road.
        $fake = $this->provider(ProviderCategory::Dns, 'fake_dns', controlled: true);

        $verdict = $this->evaluate(Product::Dns, [...$this->sharedMet(), $fake]);

        $this->assertSame(ProductReadinessState::ReadyForTest, $verdict->state);
    }

    #[Test]
    public function a_real_proven_provider_outside_production_reaches_real_validation(): void
    {
        $staging = $this->provider(ProviderCategory::Dns, environment: DeploymentEnvironment::Staging, state: ProviderState::Ready);

        $verdict = $this->evaluate(Product::Dns, [...$this->sharedMet(), $staging]);

        $this->assertSame(ProductReadinessState::ReadyForRealValidation, $verdict->state);
        $this->assertStringContainsString('nothing real is enabled in production', $verdict->detail);
    }

    #[Test]
    public function a_real_provider_proven_in_production_but_not_enabled_is_still_only_real_validation(): void
    {
        $ready = $this->provider(ProviderCategory::Dns, state: ProviderState::Ready);

        $verdict = $this->evaluate(Product::Dns, [...$this->sharedMet(), $ready]);

        $this->assertSame(ProductReadinessState::ReadyForRealValidation, $verdict->state);
        $this->assertStringContainsString('proven in production but not enabled', $verdict->detail);
    }

    #[Test]
    public function a_real_provider_enabled_in_production_reaches_production_and_the_engine_stops_there(): void
    {
        $verdict = $this->evaluate(Product::Dns, [...$this->sharedMet(), $this->provider(ProviderCategory::Dns)]);

        $this->assertSame(ProductReadinessState::ReadyForProduction, $verdict->state);
        $this->assertNull($verdict->blocker);
        $this->assertStringContainsString('Not yet declared sellable', $verdict->detail);
    }

    #[Test]
    public function the_best_real_provider_wins_over_a_fake_beside_it(): void
    {
        $providers = [
            ...$this->sharedMet(),
            $this->provider(ProviderCategory::Dns, 'fake', controlled: true, environment: DeploymentEnvironment::Staging, state: ProviderState::Ready),
            $this->provider(ProviderCategory::Dns),
        ];

        $this->assertSame(ProductReadinessState::ReadyForProduction, $this->evaluate(Product::Dns, $providers)->state);
    }

    #[Test]
    public function a_provider_that_answered_but_lacks_a_capability_the_product_calls_is_a_configuration_blocker(): void
    {
        $partial = $this->provider(ProviderCategory::Dns, supports: ['create_zone', 'records']);

        $verdict = $this->evaluate(Product::Dns, [...$this->sharedMet(), $partial]);

        $this->assertSame(ProductReadinessState::NotReady, $verdict->state);
        $this->assertSame(BlockerReason::Configuration, $verdict->blocker);
        $this->assertStringContainsString('does not support delete_zone, reconcile', $verdict->detail);
    }

    #[Test]
    public function a_capability_the_product_never_calls_does_not_hold_it_back(): void
    {
        // Compute asks about gpu_passthrough for the GPU product; the VPS
        // product's own requirement does not list it.
        $compute = $this->provider(ProviderCategory::Compute, 'proxmox', supports: ['create', 'start', 'stop', 'reboot', 'resize', 'reinstall', 'suspend', 'unsuspend', 'console', 'destroy', 'templates', 'task_polling']);
        $rdns = $this->provider(ProviderCategory::ReverseDns, 'cloudflare_rdns');

        $this->assertSame(ProductReadinessState::ReadyForProduction, $this->evaluate(Product::Vps, [...$this->sharedMet(), $compute, $rdns])->state);
    }

    #[Test]
    public function a_blocked_provider_passes_its_own_blocker_up_to_the_product(): void
    {
        $blocked = $this->provider(
            ProviderCategory::Dns,
            state: ProviderState::Blocked,
            readiness: ReadinessState::NotReady,
            blocker: BlockerReason::Credentials,
            name: 'dns-live',
        );

        $verdict = $this->evaluate(Product::Dns, [...$this->sharedMet(), $blocked]);

        $this->assertSame(ProductReadinessState::NotReady, $verdict->state);
        $this->assertSame(BlockerReason::Credentials, $verdict->blocker);
        $this->assertStringContainsString('dns-live is not_ready (blocked)', $verdict->detail);
    }

    #[Test]
    public function a_disabled_provider_is_not_proven_whatever_it_proved_before(): void
    {
        $disabled = $this->provider(ProviderCategory::Dns, state: ProviderState::Disabled);

        $this->assertSame(ProductReadinessState::NotReady, $this->evaluate(Product::Dns, [...$this->sharedMet(), $disabled])->state);
    }

    #[Test]
    public function the_shared_requirements_hold_every_product_back_at_once(): void
    {
        // A perfect DNS provider and no way to take money.
        $verdict = $this->evaluate(Product::Dns, [$this->provider(ProviderCategory::Dns)]);

        $this->assertSame(ProductReadinessState::NotReady, $verdict->state);
        $this->assertStringStartsWith('payment:', $verdict->detail);

        // The row for the shared requirement says so, marked shared.
        $payment = array_values(array_filter($verdict->requirements, fn ($r) => $r->requirement->category === ProviderCategory::Payment))[0];
        $this->assertTrue($payment->requirement->shared);
        $this->assertSame(ProductReadinessState::NotReady, $payment->satisfiedUpTo);
    }

    #[Test]
    public function a_dependency_that_is_behind_caps_the_dependent_with_a_dependency_blocker(): void
    {
        $installer = $this->provider(ProviderCategory::WordPressInstaller, 'cpanel');

        $verdict = $this->evaluate(
            Product::WordPress,
            [...$this->sharedMet(), $installer],
            ['shared_hosting' => ProductReadinessState::ReadyForTest],
        );

        $this->assertSame(ProductReadinessState::ReadyForTest, $verdict->state);
        $this->assertSame(BlockerReason::Dependency, $verdict->blocker);
        $this->assertSame('Depends on shared_hosting, which is ready_for_test.', $verdict->detail);
    }

    #[Test]
    public function a_dependency_that_was_declared_sellable_only_lends_production_not_the_declaration(): void
    {
        $installer = $this->provider(ProviderCategory::WordPressInstaller, 'cpanel');

        $verdict = $this->evaluate(
            Product::WordPress,
            [...$this->sharedMet(), $installer],
            ['shared_hosting' => ProductReadinessState::ReadyToSell],
        );

        $this->assertSame(ProductReadinessState::ReadyForProduction, $verdict->state);
    }

    #[Test]
    public function the_own_requirement_is_blamed_before_the_shared_one_when_both_fail(): void
    {
        $verdict = $this->evaluate(Product::Backups, [], ['vps' => ProductReadinessState::ReadyForProduction]);

        $this->assertStringStartsWith('backup:', $verdict->detail);
    }

    #[Test]
    public function every_product_has_at_least_one_requirement_of_its_own_and_carries_the_shared_ones(): void
    {
        foreach (Product::cases() as $product) {
            $own = $this->requirements->own($product);
            $all = $this->requirements->for($product);

            $this->assertNotEmpty($own, "{$product->value} needs nothing of its own, which cannot be true of a product.");
            $this->assertCount(count($own) + 2, $all);

            foreach ($all as $requirement) {
                $this->assertNotEmpty($requirement->capabilities);
                foreach ($requirement->capabilities as $capability) {
                    $this->assertContains(
                        $capability,
                        $requirement->category->capabilities(),
                        "{$product->value} asks {$requirement->category->value} for {$capability}, which that category is never asked about.",
                    );
                }
            }
        }
    }

    #[Test]
    public function dependency_order_puts_every_dependency_before_its_dependent(): void
    {
        $order = Product::inDependencyOrder();

        $this->assertCount(count(Product::cases()), $order);

        foreach ($order as $index => $product) {
            foreach ($product->dependsOn() as $dependency) {
                $this->assertLessThan($index, array_search($dependency, $order, strict: true));
            }
        }
    }
}
