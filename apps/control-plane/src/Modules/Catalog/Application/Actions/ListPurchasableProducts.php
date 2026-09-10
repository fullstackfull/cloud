<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\ProductReadiness\Application\Services\ProductSellability;

/**
 * The catalogue a customer is allowed to see.
 *
 * "Allowed to see" is one predicate — active, public, and of a kind the
 * readiness engine currently permits selling — and it lives here rather than
 * in a controller so that the browse endpoint, a future sitemap builder and
 * anything else that lists products all answer the same question. A product
 * that is inactive or unlisted is absent, not forbidden: a 403 would confirm
 * that a slug somebody guessed is real.
 *
 * The readiness half is the same decision the checkout makes
 * ({@see ProductSellability}), so a product this listing offers is a product
 * the order guard would accept, and one it would refuse is not on the shelf.
 * It used to be: the guard was consulted at order time only, and a product
 * whose sellability had been withdrawn stayed listed with a button that
 * answered 409.
 *
 * The plan count is counted through the same predicate, so a product whose
 * plans are all unlisted reports zero rather than advertising configurations
 * that cannot be bought.
 *
 * The page-size ceiling is enforced here rather than only at the HTTP edge.
 * A bound that lives in a controller is a bound that a queue worker, a console
 * command or the next caller of this action does not have, and "one request
 * must not be able to read the whole table" is a property of listing the
 * catalogue, not of the controller that happens to list it today.
 *
 * @phpstan-type ProductPaginator LengthAwarePaginator<int, Product>
 */
final class ListPurchasableProducts
{
    /** Nobody gets more than this in one page, whoever is asking. */
    public const int MAX_PER_PAGE = 100;

    private const int DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly ProductSellability $sellability,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function execute(?ProductKind $kind = null, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        // Clamped, not rejected: a caller asking for more than the ceiling
        // gets the ceiling. Zero and negatives are a nonsense page rather than
        // a small one, so they fall back to the default instead of to 1.
        $perPage = $perPage < 1 ? self::DEFAULT_PER_PAGE : min($perPage, self::MAX_PER_PAGE);

        $sellable = $this->sellability->sellableCatalogueKinds();

        return Product::query()
            ->purchasable()
            ->when($sellable !== null, static fn (Builder $query): Builder => $query->whereIn('kind', $sellable ?? []))
            ->when($kind !== null, static fn (Builder $query): Builder => $query->where('kind', $kind?->value))
            ->withCount(['plans' => self::onlyPurchasablePlans(...)])
            // sort_order is the merchandising decision; slug is the tiebreak
            // that keeps paging stable, because a page boundary that shifts
            // between requests silently drops rows from the second page.
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->paginate($perPage);
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
