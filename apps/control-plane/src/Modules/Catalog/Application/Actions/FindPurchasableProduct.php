<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Catalog\Domain\Services\CataloguePriceVisibility;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;

/**
 * One product, addressed by slug or id, with the plans a customer may buy.
 *
 * Both identifiers resolve through the same visibility predicate, so a product
 * that is not on sale is missing whichever way it is asked for. The lookup
 * throws ModelNotFoundException rather than returning null: the renderer turns
 * that into the same 404 an invented slug gets, and "hidden" and "never
 * existed" must be indistinguishable from outside.
 */
final readonly class FindPurchasableProduct
{
    public function __construct(
        private CataloguePriceVisibility $prices,
    ) {}

    public function execute(string $identifier, string $currency): Product
    {
        /** @var Product $product */
        $product = Product::query()
            ->purchasable()
            ->where(static fn (Builder $query): Builder => $query
                ->where('slug', $identifier)
                ->orWhere('id', $identifier))
            ->with(['plans' => static fn ($relation) => $relation
                ->purchasable()
                ->with('prices')
                ->orderBy('sort_order')
                ->orderBy('slug')])
            ->firstOrFail();

        $product->plans->each(fn (Plan $plan): Plan => $this->prices->apply($plan, $currency));

        return $product;
    }
}
