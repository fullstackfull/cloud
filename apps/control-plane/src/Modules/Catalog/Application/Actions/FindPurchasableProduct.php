<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Catalog\Domain\Services\CataloguePriceVisibility;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\ProductReadiness\Application\Services\ProductSellability;

/**
 * One product, addressed by slug or id, with the plans a customer may buy.
 *
 * Both identifiers resolve through the same visibility predicate, so a product
 * that is not on sale is missing whichever way it is asked for — including a
 * product of a kind the readiness engine will not currently sell, which is
 * absent by direct link exactly as it is absent from the listing. The lookup
 * throws ModelNotFoundException rather than returning null: the renderer turns
 * that into the same 404 an invented slug gets, and "hidden" and "never
 * existed" must be indistinguishable from outside.
 *
 * The plans are a collection like any other, so they are bounded like any
 * other. A relation is exactly where a page-size ceiling gets forgotten: the
 * rows arrive through an eager load rather than through a paginator, and one
 * GET on a product that grew two thousand configurations would otherwise pull
 * every one of them — and every price row hanging off each — into memory and
 * into the response. The true count is loaded alongside, so a client can see
 * that the list it was handed is shorter than the catalogue rather than
 * believing the product has only MAX_PLANS configurations.
 */
final readonly class FindPurchasableProduct
{
    /**
     * The most plans one product detail response will carry. Deliberately the
     * same number as the listing's page ceiling: there is one answer to "how
     * many rows may a single request return", not one per endpoint.
     */
    public const int MAX_PLANS = ListPurchasableProducts::MAX_PER_PAGE;

    public function __construct(
        private CataloguePriceVisibility $prices,
        private ProductSellability $sellability,
    ) {}

    public function execute(string $identifier, string $currency): Product
    {
        $sellable = $this->sellability->sellableCatalogueKinds();

        /** @var Product $product */
        $product = Product::query()
            ->purchasable()
            ->when($sellable !== null, static fn (Builder $query): Builder => $query->whereIn('kind', $sellable ?? []))
            ->where(static fn (Builder $query): Builder => $query
                ->where('slug', $identifier)
                ->orWhere('id', $identifier))
            // Counted through the same predicate the plans are loaded through,
            // so the count and the list can never disagree about what is on
            // sale — only about how much of it fits in one response.
            ->withCount(['plans' => self::onlyPurchasablePlans(...)])
            ->with(['plans' => static fn ($relation) => $relation
                ->purchasable()
                ->with('prices')
                ->orderBy('sort_order')
                ->orderBy('slug')
                ->limit(self::MAX_PLANS)])
            ->firstOrFail();

        $product->plans->each(fn (Plan $plan): Plan => $this->prices->apply($plan, $currency));

        return $product;
    }

    /**
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    private static function onlyPurchasablePlans(Builder $query): Builder
    {
        return $query->purchasable();
    }
}
