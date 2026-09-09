<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Application\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProductVerdict;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\ProviderFacts;
use Lynomia\Modules\ProductReadiness\Domain\DTOs\RequirementVerdict;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductReadinessEvaluator;
use Lynomia\Modules\ProductReadiness\Domain\Services\ProductRequirements;
use Lynomia\Modules\ProductReadiness\Infrastructure\Models\ProductReadiness;
use Lynomia\Modules\Providers\Domain\Services\ProviderCatalogue;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;

/**
 * Assess one product and record the conclusion.
 *
 * Dependencies are evaluated first, in memory, from the same provider facts,
 * so the verdict for WordPress is reached from the verdict for shared hosting
 * as it IS now — not as a row written an hour ago said.
 *
 * Two things this action does that the evaluator does not: it applies a
 * standing sellability declaration (production + declared = ready_to_sell),
 * and it withdraws that declaration, audited, the moment the evaluator says
 * the product has fallen below production. A product is never sellable on
 * the strength of a provider that has since stopped answering.
 */
final readonly class AssessProduct
{
    public function __construct(
        private ProductRequirements $requirements,
        private ProductReadinessEvaluator $evaluator,
        private ProviderCatalogue $catalogue,
        private RecordActAtomically $record,
    ) {}

    /**
     * @param  Collection<int, ProviderInstance>|null  $providers  Already-loaded providers (with capabilities), so a sweep loads them once.
     */
    public function execute(Product $product, ?Collection $providers = null): ProductVerdict
    {
        $facts = $this->facts($providers ?? $this->providers());
        $memo = [];
        $verdict = $this->evaluate($product, $facts, $memo);

        return $this->persist($verdict);
    }

    /**
     * @return Collection<int, ProviderInstance>
     */
    public function providers(): Collection
    {
        return ProviderInstance::query()->with('capabilities')->get();
    }

    /**
     * @param  list<ProviderFacts>  $facts
     * @param  array<string, ProductReadinessState>  $memo
     */
    private function evaluate(Product $product, array $facts, array &$memo): ProductVerdict
    {
        $dependencies = [];

        foreach ($product->dependsOn() as $dependency) {
            if (! isset($memo[$dependency->value])) {
                $memo[$dependency->value] = $this->evaluate($dependency, $facts, $memo)->state;
            }

            $dependencies[$dependency->value] = $memo[$dependency->value];
        }

        return $this->evaluator->evaluate($product, $this->requirements->for($product), $facts, $dependencies);
    }

    private function persist(ProductVerdict $verdict): ProductVerdict
    {
        /** @var array{verdict: ProductVerdict, row: ProductReadiness, previous: ProductReadinessState, withdrawn: bool} $outcome */
        $outcome = $this->record->execute(
            act: function () use ($verdict): array {
                $row = ProductReadiness::query()->firstOrCreate(['product' => $verdict->product->value]);
                $previous = $row->state;
                $now = CarbonImmutable::now();

                $state = $verdict->state;
                $withdrawn = false;

                if ($row->isDeclaredSellable()) {
                    if ($state === ProductReadinessState::ReadyForProduction) {
                        $state = ProductReadinessState::ReadyToSell;
                    } else {
                        // The declaration was true of providers that no longer
                        // qualify. It is withdrawn here, by the assessment,
                        // rather than left standing for a person to notice.
                        $withdrawn = true;
                        $row->forceFill([
                            'sellability_withdrawn_at' => $now,
                            'sellability_withdrawn_reason' => sprintf('Withdrawn by assessment: %s', $verdict->detail ?? $state->value),
                        ]);
                    }
                }

                $row->forceFill([
                    'state' => $state,
                    'blocker' => $verdict->blocker,
                    'detail' => $verdict->detail,
                    'requirements' => array_map(static fn (RequirementVerdict $r): array => $r->toArray(), $verdict->requirements),
                    'dependencies' => $verdict->dependencies,
                    'assessed_at' => $now,
                    'state_changed_at' => $previous === $state ? $row->state_changed_at : $now,
                ])->save();

                $final = new ProductVerdict(
                    $verdict->product,
                    $state,
                    $verdict->requirements,
                    $verdict->dependencies,
                    $verdict->blocker,
                    $verdict->detail,
                );

                return ['verdict' => $final, 'row' => $row, 'previous' => $previous, 'withdrawn' => $withdrawn];
            },
            // Audited only when something moved: an assessment that concludes
            // what the last one concluded is not an event, and a nightly
            // sweep over seven products must not write seven rows of nothing.
            describe: static fn (array $outcome): ?AuditedAct => $outcome['previous'] === $outcome['verdict']->state && ! $outcome['withdrawn']
                ? null
                : new AuditedAct(
                    action: $outcome['withdrawn'] ? AuditAction::ProductSellabilityWithdrawn : AuditAction::ProductReadinessChanged,
                    subject: $outcome['row'],
                    context: [
                        'product' => $outcome['verdict']->product->value,
                        'from' => $outcome['previous']->value,
                        'to' => $outcome['verdict']->state->value,
                        'blocker' => $outcome['verdict']->blocker?->value,
                        'detail' => $outcome['verdict']->detail,
                        'automatic' => true,
                    ],
                ),
        );

        return $outcome['verdict'];
    }

    /**
     * @param  Collection<int, ProviderInstance>  $providers
     * @return list<ProviderFacts>
     */
    private function facts(Collection $providers): array
    {
        $controlled = $this->catalogue->controlledDrivers();

        return $providers->map(fn (ProviderInstance $provider): ProviderFacts => new ProviderFacts(
            id: $provider->id,
            name: $provider->name,
            category: $provider->category,
            driver: $provider->driver,
            controlled: in_array($provider->driver, $controlled, strict: true),
            environment: $provider->environment,
            state: $provider->state,
            readiness: $provider->readiness,
            blocker: $provider->blocker,
            capabilities: $provider->capabilities
                ->mapWithKeys(static fn (ProviderCapability $c): array => [$c->capability => $c->state])
                ->all(),
        ))->values()->all();
    }
}
