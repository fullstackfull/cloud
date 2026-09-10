<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Catalog\Domain\Services\CataloguePriceVisibility;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\ProductReadiness\Application\Services\ProductSellability;

/**
 * One plan, addressed by slug or id, priced in the customer's currency.
 *
 * A plan inherits its product's visibility. A public plan hanging off an
 * unlisted or retired product is not purchasable, so it is not visible either
 * — otherwise a product could be withdrawn from sale while every one of its
 * plans stayed reachable by direct link.
 */
final readonly class FindPurchasablePlan
{
    public function __construct(
        private CataloguePriceVisibility $prices,
        private ProductSellability $sellability,
    ) {}

    public function execute(string $identifier, string $currency): Plan
    {
        $sellable = $this->sellability->sellableCatalogueKinds();

        /** @var Plan $plan */
        $plan = Plan::query()
            ->purchasable()
            ->whereIn('product_id', self::purchasableProductIds($sellable))
            ->where(static fn (Builder $query): Builder => $query
                ->where('slug', $identifier)
                ->orWhere('id', $identifier))
            ->with(['prices', 'product'])
            ->firstOrFail();

        return $this->prices->apply($plan, $currency);
    }

    /**
     * The products a plan may hang off: on sale, and of a kind the readiness
     * engine currently permits selling — the same gate the product listing
     * applies, so a plan inherits its product's sellability as it inherits
     * its visibility.
     *
     * @param  list<string>|null  $sellable
     * @return Builder<Product>
     */
    private static function purchasableProductIds(?array $sellable): Builder
    {
        return Product::query()
            ->purchasable()
            ->when($sellable !== null, static fn (Builder $query): Builder => $query->whereIn('kind', $sellable ?? []))
            ->select('id');
    }
}
