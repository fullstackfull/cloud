<?php

declare(strict_types=1);

namespace Lynomia\Modules\ProductReadiness\Application\Actions;

use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Infrastructure\Models\ProductReadiness;

/**
 * Every product, from one load of the providers.
 *
 * Runs whenever a provider's readiness moves and on demand from the screen.
 * Dependency order, so a sweep never writes a dependent's row from a
 * dependency's stale one.
 */
final readonly class AssessAllProducts
{
    public function __construct(
        private AssessProduct $assess,
    ) {}

    /**
     * @return array{examined: int, changed: int, products: array<string, string>}
     */
    public function execute(): array
    {
        $providers = $this->assess->providers();
        $before = ProductReadiness::query()
            ->pluck('state', 'product')
            ->map(static fn ($state): string => $state->value)
            ->all();

        $states = [];

        foreach (Product::inDependencyOrder() as $product) {
            $verdict = $this->assess->execute($product, $providers);
            $states[$product->value] = $verdict->state->value;
        }

        $changed = count(array_filter(
            $states,
            static fn (string $state, string $product): bool => ($before[$product] ?? null) !== $state,
            ARRAY_FILTER_USE_BOTH,
        ));

        return ['examined' => count($states), 'changed' => $changed, 'products' => $states];
    }
}
