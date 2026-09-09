<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Domain\Services;

use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProductVerdict;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProviderFacts;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\Requirement;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\RequirementVerdict;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Shared\Domain\Enums\BlockerReason;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;

/**
 * Where a product stands, from what its providers have proven.
 *
 * A pure function: products, requirements and provider facts in, a verdict
 * out. The rules, in the order they are applied to each requirement:
 *
 *   1. Is there a PROVEN provider in the category — one that answered a
 *      connection test, had its capabilities discovered, and is Ready or
 *      Enabled — that supports every capability the product calls? If not,
 *      the requirement is unmet and the product is not ready. The blocker is
 *      the best candidate's own blocker, so a revoked credential three rows
 *      away reads as "blocked: credentials" here too. That is propagation.
 *   2. Is any such provider real — not a controlled driver? If only fakes
 *      qualify, the requirement stops at ready_for_test. A fake provider
 *      cannot satisfy anything above the rehearsal rung, whatever environment
 *      it was registered in and whatever state an operator put it in.
 *   3. Is any real one Enabled in production? If not, the requirement stops
 *      at ready_for_real_validation.
 *   4. Otherwise ready_for_production. The engine never says ready_to_sell.
 *
 * The product's rung is the lowest of its requirements' rungs and of its
 * dependencies' rungs. A dependency that is behind caps the dependent with a
 * dependency blocker naming it, because the fix is over there.
 */
final readonly class ProductReadinessEvaluator
{
    /**
     * @param  list<Requirement>  $requirements
     * @param  list<ProviderFacts>  $providers
     * @param  array<string, ProductReadinessState>  $dependencies  Verdicts already reached for what this product leans on, keyed by product value.
     */
    public function evaluate(Product $product, array $requirements, array $providers, array $dependencies): ProductVerdict
    {
        $verdicts = array_map(fn (Requirement $requirement): RequirementVerdict => $this->judge($requirement, $providers), $requirements);

        $state = ProductReadinessState::ReadyForProduction;
        $blocker = null;
        $detail = null;

        foreach ($product->dependsOn() as $dependency) {
            $reached = $dependencies[$dependency->value] ?? ProductReadinessState::NotReady;
            // A declaration on the dependency does not carry over: what this
            // product inherits is how far its dependency has been proven.
            if ($reached === ProductReadinessState::ReadyToSell) {
                $reached = ProductReadinessState::ReadyForProduction;
            }

            if (! $reached->atLeast($state)) {
                $state = $reached;
                $blocker = BlockerReason::Dependency;
                $detail = sprintf('Depends on %s, which is %s.', $dependency->value, $reached->value);
            }
        }

        foreach ($verdicts as $verdict) {
            if (! $verdict->satisfiedUpTo->atLeast($state)) {
                $state = $verdict->satisfiedUpTo;
                $blocker = $verdict->blocker;
                $detail = sprintf('%s: %s', $verdict->requirement->category->value, $verdict->detail);
            }
        }

        if ($state === ProductReadinessState::ReadyForProduction) {
            $blocker = null;
            $detail = 'Every requirement is met by a real provider enabled in production. Not yet declared sellable.';
        }

        return new ProductVerdict(
            $product,
            $state,
            $verdicts,
            array_map(static fn (ProductReadinessState $s): string => $s->value, $dependencies),
            $blocker,
            $detail,
        );
    }

    /**
     * @param  list<ProviderFacts>  $providers
     */
    private function judge(Requirement $requirement, array $providers): RequirementVerdict
    {
        $inCategory = array_values(array_filter(
            $providers,
            static fn (ProviderFacts $p): bool => $p->category === $requirement->category,
        ));

        if ($inCategory === []) {
            return new RequirementVerdict(
                $requirement,
                ProductReadinessState::NotReady,
                null,
                null,
                BlockerReason::Dependency,
                sprintf('No %s provider is registered.', $requirement->category->value),
            );
        }

        $proven = array_values(array_filter($inCategory, static fn (ProviderFacts $p): bool => $p->isProven()));

        if ($proven === []) {
            // Propagate: the nearest candidate's blocker is this requirement's
            // blocker. An operator reading "blocked: credentials" on the VPS
            // product goes to the credential, not to the product.
            $nearest = $this->nearest($inCategory);

            return new RequirementVerdict(
                $requirement,
                ProductReadinessState::NotReady,
                $nearest->id,
                $nearest->name,
                $nearest->blocker ?? BlockerReason::Configuration,
                sprintf('%s is %s (%s).', $nearest->name, $nearest->readiness->value, $nearest->state->value),
            );
        }

        $capable = array_values(array_filter(
            $proven,
            static fn (ProviderFacts $p): bool => array_all($requirement->capabilities, static fn (string $c): bool => $p->supports($c)),
        ));

        if ($capable === []) {
            $first = $proven[0];
            $missing = array_values(array_filter($requirement->capabilities, static fn (string $c): bool => ! $first->supports($c)));

            return new RequirementVerdict(
                $requirement,
                ProductReadinessState::NotReady,
                $first->id,
                $first->name,
                BlockerReason::Configuration,
                sprintf('%s answered but does not support %s.', $first->name, implode(', ', $missing)),
            );
        }

        $real = array_values(array_filter($capable, static fn (ProviderFacts $p): bool => ! $p->controlled));

        if ($real === []) {
            $fake = $capable[0];

            return new RequirementVerdict(
                $requirement,
                ProductReadinessState::ReadyForTest,
                $fake->id,
                $fake->name,
                BlockerReason::Configuration,
                sprintf('Only %s meets this, and it is a controlled %s driver: enough to rehearse, never to validate or sell.', $fake->name, $fake->driver),
            );
        }

        $live = array_values(array_filter(
            $real,
            static fn (ProviderFacts $p): bool => $p->environment === DeploymentEnvironment::Production && $p->state === ProviderState::Enabled,
        ));

        if ($live === []) {
            $best = $real[0];

            return new RequirementVerdict(
                $requirement,
                ProductReadinessState::ReadyForRealValidation,
                $best->id,
                $best->name,
                BlockerReason::Configuration,
                $best->environment === DeploymentEnvironment::Production
                    ? sprintf('%s is proven in production but not enabled.', $best->name)
                    : sprintf('%s is proven in %s; nothing real is enabled in production.', $best->name, $best->environment->value),
            );
        }

        return new RequirementVerdict(
            $requirement,
            ProductReadinessState::ReadyForProduction,
            $live[0]->id,
            $live[0]->name,
            null,
            sprintf('%s is enabled in production and supports everything asked.', $live[0]->name),
        );
    }

    /**
     * The candidate closest to being useful, so the propagated blocker is the
     * most actionable one rather than the first row's.
     *
     * @param  non-empty-list<ProviderFacts>  $candidates
     */
    private function nearest(array $candidates): ProviderFacts
    {
        usort($candidates, static function (ProviderFacts $a, ProviderFacts $b): int {
            $rank = static fn (ProviderFacts $p): int => match (true) {
                $p->state === ProviderState::Disabled => 0,
                $p->blocker !== null => 1,
                default => 2,
            };

            return $rank($b) <=> $rank($a);
        });

        return $candidates[0];
    }
}
