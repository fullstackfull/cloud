<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Catalog\Domain\Services\CataloguePriceVisibility;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;

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
    ) {}

    public function execute(string $identifier, string $currency): Plan
    {
        /** @var Plan $plan */
        $plan = Plan::query()
            ->purchasable()
            ->whereHas('product', self::onlyPurchasableProducts(...))
            ->where(static fn (Builder $query): Builder => $query
                ->where('slug', $identifier)
                ->orWhere('id', $identifier))
            ->with(['prices', 'product'])
            ->firstOrFail();

        return $this->prices->apply($plan, $currency);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private static function onlyPurchasableProducts(Builder $query): Builder
    {
        return $query->purchasable();
    }
}
