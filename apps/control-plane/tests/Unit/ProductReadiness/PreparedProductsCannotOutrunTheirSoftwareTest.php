<?php

declare(strict_types=1);

namespace Tests\Unit\ProductReadiness;

use Lynomia\Modules\ProductReadiness\Domain\DTOs\CapacityFacts;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProductVerdict;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProviderFacts;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\RequirementVerdict;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductSoftwareState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductReadinessEvaluator;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\ProductReadiness\Domain\Services\ReadinessQuestions;
use Lynomia\Modules\Providers\Domain\Enums\CapabilityState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Shared\Domain\Enums\ReadinessState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two caps the scope addendum added to the ladder, and the questions it
 * asked readiness to answer.
 *
 * The property throughout: a product's providers can be as real and as live
 * as they like, and the product still cannot pass real validation while its
 * software is prepared or readiness-only, nor be ready at all while the
 * hardware it needs is absent from the estate. Nothing here can be fixed by
 * configuration, and the blocker says so.
 */
final class PreparedProductsCannotOutrunTheirSoftwareTest extends TestCase
{
    private ProductReadinessEvaluator $evaluator;

    private ProductRequirements $requirements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new ProductReadinessEvaluator;
        $this->requirements = new ProductRequirements;
    }

    private function live(ProviderCategory $category, string $driver = 'real'): ProviderFacts
    {
        $capabilities = [];
        foreach ($category->capabilities() as $capability) {
            $capabilities[$capability] = CapabilityState::Supported;
        }

        return new ProviderFacts(
            id: '01J'.strtoupper(substr(md5($category->value.$driver), 0, 20)),
            name: $category->value.'-'.$driver,
            category: $category,
            driver: $driver,
            controlled: false,
            environment: DeploymentEnvironment::Production,
            state: ProviderState::Enabled,
            readiness: ReadinessState::ReadyForProduction,
            blocker: null,
            capabilities: $capabilities,
        );
    }

    /**
     * Every provider every product could want, real and live.
     *
     * @return list<ProviderFacts>
     */
    private function everythingLive(): array
    {
        return array_map(fn (ProviderCategory $c): ProviderFacts => $this->live($c), ProviderCategory::cases());
    }

    /**
     * @param  list<ProviderFacts>  $providers
     * @param  array<string, ProductReadinessState>  $dependencies
     */
    private function evaluate(Product $product, array $providers, array $dependencies = [], ?CapacityFacts $capacity = null): ProductVerdict
    {
        return $this->evaluator->evaluate($product, $this->requirements->for($product), $providers, $dependencies, $capacity);
    }

    /**
     * @return array<string, ProductReadinessState>
     */
    private function dependenciesAt(Product $product, ProductReadinessState $state): array
    {
        $out = [];
        foreach ($product->dependsOn() as $dependency) {
            $out[$dependency->value] = $state;
        }

        return $out;
    }

    #[Test]
    public function a_prepared_product_on_real_live_providers_stops_at_real_validation_with_not_implemented(): void
    {
        foreach ([Product::Cdn, Product::ObjectStorage, Product::EmailHosting] as $product) {
            $verdict = $this->evaluate($product, $this->everythingLive(), $this->dependenciesAt($product, ProductReadinessState::ReadyForProduction));

            $this->assertSame(ProductReadinessState::ReadyForRealValidation, $verdict->state, $product->value);
            $this->assertSame(BlockerReason::NotImplemented, $verdict->blocker, $product->value);
            $this->assertStringContainsString('software is prepared', (string) $verdict->detail);

            // Every requirement itself reached production: the cap is the
            // product's, not a provider's, and the rows say so.
            foreach ($verdict->requirements as $row) {
                $this->assertSame(ProductReadinessState::ReadyForProduction, $row->satisfiedUpTo, $product->value.' '.$row->requirement->category->value);
            }
        }
    }

    #[Test]
    public function a_readiness_only_product_is_capped_the_same_way_and_says_readiness_only(): void
    {
        $verdict = $this->evaluate(Product::ManagedKubernetes, $this->everythingLive(), $this->dependenciesAt(Product::ManagedKubernetes, ProductReadinessState::ReadyToSell));

        $this->assertSame(ProductSoftwareState::ReadinessOnly, Product::ManagedKubernetes->softwareState());
        $this->assertSame(ProductReadinessState::ReadyForRealValidation, $verdict->state);
        $this->assertSame(BlockerReason::NotImplemented, $verdict->blocker);
        $this->assertStringContainsString('readiness_only', (string) $verdict->detail);
    }

    #[Test]
    public function the_software_cap_never_lifts_a_product_that_is_lower_for_a_provider_reason(): void
    {
        // No CDN provider at all: the blocker is the missing provider, not
        // the missing software, because the provider is the thing to fix.
        $verdict = $this->evaluate(Product::Cdn, [$this->live(ProviderCategory::Dns), $this->live(ProviderCategory::Payment), $this->live(ProviderCategory::Email)], ['dns' => ProductReadinessState::ReadyForProduction]);

        $this->assertSame(ProductReadinessState::NotReady, $verdict->state);
        $this->assertSame(BlockerReason::Dependency, $verdict->blocker);
        $this->assertStringContainsString('No cdn provider is registered', (string) $verdict->detail);
    }

    #[Test]
    public function a_complete_product_is_not_touched_by_the_cap(): void
    {
        $verdict = $this->evaluate(Product::Dns, $this->everythingLive());

        $this->assertSame(ProductReadinessState::ReadyForProduction, $verdict->state);
        $this->assertNull($verdict->blocker);
    }

    #[Test]
    public function gpu_compute_is_blocked_on_hardware_while_the_estate_has_no_gpu_whatever_the_provider_says(): void
    {
        $verdict = $this->evaluate(Product::GpuCompute, $this->everythingLive(), ['vps' => ProductReadinessState::ReadyForProduction], CapacityFacts::empty());

        $this->assertSame(ProductReadinessState::NotReady, $verdict->state);
        $this->assertSame(BlockerReason::Hardware, $verdict->blocker);
        $this->assertStringContainsString('No GPU device', (string) $verdict->detail);
    }

    #[Test]
    public function with_a_gpu_in_the_estate_gpu_compute_climbs_to_the_software_cap_and_no_further(): void
    {
        $verdict = $this->evaluate(Product::GpuCompute, $this->everythingLive(), ['vps' => ProductReadinessState::ReadyForProduction], new CapacityFacts(gpuDevicesAvailable: 1));

        $this->assertSame(ProductReadinessState::ReadyForRealValidation, $verdict->state);
        $this->assertSame(BlockerReason::NotImplemented, $verdict->blocker);
    }

    #[Test]
    public function a_compute_provider_without_passthrough_blocks_gpu_compute_and_not_vps(): void
    {
        $compute = $this->live(ProviderCategory::Compute);
        $without = new ProviderFacts(
            $compute->id, $compute->name, $compute->category, $compute->driver, false,
            DeploymentEnvironment::Production, ProviderState::Enabled, ReadinessState::ReadyForProduction, null,
            [...$compute->capabilities, 'gpu_passthrough' => CapabilityState::Unsupported],
        );
        $others = array_values(array_filter($this->everythingLive(), static fn (ProviderFacts $p): bool => $p->category !== ProviderCategory::Compute));

        $vps = $this->evaluate(Product::Vps, [...$others, $without]);
        $gpu = $this->evaluate(Product::GpuCompute, [...$others, $without], ['vps' => ProductReadinessState::ReadyForProduction], new CapacityFacts(gpuDevicesAvailable: 2));

        $this->assertSame(ProductReadinessState::ReadyForProduction, $vps->state);
        $this->assertSame(ProductReadinessState::NotReady, $gpu->state);
        $this->assertSame(BlockerReason::Configuration, $gpu->blocker);
        $this->assertStringContainsString('gpu_passthrough', (string) $gpu->detail);
    }

    #[Test]
    public function an_optional_capability_the_provider_lacks_is_not_a_blocker(): void
    {
        $backup = $this->live(ProviderCategory::Backup);
        $noBrowse = new ProviderFacts(
            $backup->id, $backup->name, $backup->category, $backup->driver, false,
            DeploymentEnvironment::Production, ProviderState::Enabled, ReadinessState::ReadyForProduction, null,
            [...$backup->capabilities, 'file_browse' => CapabilityState::Unsupported, 'file_restore' => CapabilityState::Unsupported],
        );
        $others = array_values(array_filter($this->everythingLive(), static fn (ProviderFacts $p): bool => $p->category !== ProviderCategory::Backup));

        $verdict = $this->evaluate(Product::Backups, [...$others, $noBrowse], ['vps' => ProductReadinessState::ReadyForProduction]);

        $this->assertSame(ProductReadinessState::ReadyForProduction, $verdict->state);
        $this->assertSame(['file_browse', 'file_restore'], $verdict->requirements[0]->requirement->optional);
    }

    #[Test]
    public function the_prepared_products_lean_on_the_products_that_would_carry_them(): void
    {
        $this->assertSame([Product::Dns], Product::Cdn->dependsOn());
        $this->assertSame([Product::Dns], Product::EmailHosting->dependsOn());
        $this->assertSame([Product::Vps], Product::GpuCompute->dependsOn());
        $this->assertSame([], Product::ObjectStorage->dependsOn());
        $this->assertSame([Product::Vps, Product::Dns, Product::Backups, Product::ObjectStorage], Product::ManagedKubernetes->dependsOn());

        $order = Product::inDependencyOrder();
        foreach (Product::cases() as $product) {
            foreach ($product->dependsOn() as $dependency) {
                $this->assertLessThan(array_search($product, $order, strict: true), array_search($dependency, $order, strict: true));
            }
        }
    }

    #[Test]
    public function the_questions_are_answered_from_the_verdict_and_agree_with_the_ladder(): void
    {
        $questions = new ReadinessQuestions;
        $verdict = $this->evaluate(Product::Cdn, $this->everythingLive(), ['dns' => ProductReadinessState::ReadyForProduction]);
        $rows = array_map(static fn (RequirementVerdict $r): array => $r->toArray(), $verdict->requirements);

        $answers = $questions->answer(Product::Cdn, $verdict->state, $rows, $verdict->dependencies, false, $verdict->blocker);

        $this->assertSame([
            'software' => 'no',
            'infrastructure' => 'not_applicable',
            'provider' => 'yes',
            'credential' => 'yes',
            'licence' => 'yes',
            'capabilities' => 'yes',
            'dependencies' => 'yes',
            'real_validation' => 'no',
            'production' => 'no',
            'sellable' => 'no',
        ], $answers);

        // A missing GPU answers the infrastructure question, not a provider one.
        $gpu = $this->evaluate(Product::GpuCompute, $this->everythingLive(), ['vps' => ProductReadinessState::ReadyForProduction], CapacityFacts::empty());
        $gpuAnswers = $questions->answer(Product::GpuCompute, $gpu->state, array_map(static fn (RequirementVerdict $r): array => $r->toArray(), $gpu->requirements), $gpu->dependencies, false, $gpu->blocker);
        $this->assertSame('no', $gpuAnswers['infrastructure']);
        $this->assertSame('yes', $gpuAnswers['provider']);

        // Nothing registered at all.
        $bare = $this->evaluate(Product::ObjectStorage, []);
        $bareAnswers = $questions->answer(Product::ObjectStorage, $bare->state, array_map(static fn (RequirementVerdict $r): array => $r->toArray(), $bare->requirements), [], false, $bare->blocker);
        $this->assertSame('no', $bareAnswers['provider']);
        $this->assertSame('no', $bareAnswers['infrastructure']);
        $this->assertSame('no', $bareAnswers['capabilities']);
        $this->assertSame('not_applicable', $bareAnswers['dependencies']);
    }
}
