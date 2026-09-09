<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Services;

use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ReadinessAnswer;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;

/**
 * The questions the scope addendum says readiness must answer for every
 * product, answered from a persisted verdict and nothing else.
 *
 *   software        is the customer flow written?
 *   infrastructure  for every requirement that lives on our machines, is
 *                   there a machine? (not applicable when none does)
 *   provider        is a provider registered for every requirement?
 *   credential      is no requirement blocked on a credential?
 *   licence         is no requirement blocked on a licence?
 *   capabilities    has a proven provider answered for every requirement
 *                   with every capability the product calls?
 *   dependencies    is everything this leans on at production?
 *   real_validation has a person recorded validation evidence that stands?
 *   production      is the product at ready_for_production or above?
 *   sellable        is the product ready_to_sell?
 *
 * The blocker and the next action are the ones the verdict already carries.
 * Each answer is derived, never stored, so the twelve cannot disagree with
 * the ladder they summarise.
 */
final readonly class ReadinessQuestions
{
    /**
     * @param  list<array<string, mixed>>  $requirements  The persisted requirement verdicts.
     * @param  array<string, string>  $dependencies  Dependency product => state value.
     * @param  BlockerReason|null  $blocker  The product's own blocker, which is where a missing GPU is reported.
     * @return array<string, string>
     */
    public function answer(
        Product $product,
        ProductReadinessState $state,
        array $requirements,
        array $dependencies,
        bool $declared,
        ?BlockerReason $blocker = null,
    ): array {
        $onOurMachines = array_values(array_filter(
            $requirements,
            static fn (array $r): bool => ProviderCategory::from((string) $r['category'])->needsServer(),
        ));

        $everyRequirementHasAProvider = array_all($requirements, static fn (array $r): bool => $r['provider_id'] !== null);

        $blockedOn = static fn (BlockerReason $reason): bool => array_any(
            $requirements,
            static fn (array $r): bool => $r['blocker'] === $reason->value,
        );

        $everyRequirementAtLeast = static fn (ProductReadinessState $rung): bool => array_all(
            $requirements,
            static fn (array $r): bool => ProductReadinessState::from((string) $r['satisfied_up_to'])->atLeast($rung),
        );

        $dependenciesAtProduction = array_all(
            $product->dependsOn(),
            static fn (Product $dependency): bool => ProductReadinessState::from($dependencies[$dependency->value] ?? ProductReadinessState::NotReady->value)
                ->atLeast(ProductReadinessState::ReadyForProduction),
        );

        return [
            'software' => ReadinessAnswer::of($product->softwareState()->maySell())->value,
            'infrastructure' => $onOurMachines === [] && $product->hardwareRequirement() === null
                ? ReadinessAnswer::NotApplicable->value
                : ReadinessAnswer::of(
                    array_all($onOurMachines, static fn (array $r): bool => $r['provider_id'] !== null)
                    && ! $blockedOn(BlockerReason::Hardware)
                    && $blocker !== BlockerReason::Hardware,
                )->value,
            'provider' => ReadinessAnswer::of($everyRequirementHasAProvider)->value,
            'credential' => ReadinessAnswer::of($everyRequirementHasAProvider && ! $blockedOn(BlockerReason::Credentials))->value,
            'licence' => ReadinessAnswer::of($everyRequirementHasAProvider && ! $blockedOn(BlockerReason::Licence))->value,
            'capabilities' => ReadinessAnswer::of($requirements !== [] && $everyRequirementAtLeast(ProductReadinessState::ReadyForTest))->value,
            'dependencies' => $product->dependsOn() === []
                ? ReadinessAnswer::NotApplicable->value
                : ReadinessAnswer::of($dependenciesAtProduction)->value,
            'real_validation' => ReadinessAnswer::of($declared)->value,
            'production' => ReadinessAnswer::of($state->atLeast(ProductReadinessState::ReadyForProduction))->value,
            'sellable' => ReadinessAnswer::of($state === ProductReadinessState::ReadyToSell)->value,
        ];
    }
}
