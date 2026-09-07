<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;

/**
 * The catalogue a customer is allowed to see.
 *
 * "Allowed to see" is one predicate — active and public — and it lives here
 * rather than in a controller so that the browse endpoint, a future sitemap
 * builder and anything else that lists products all answer the same question.
 * A product that is inactive or unlisted is absent, not forbidden: a 403 would
 * confirm that a slug somebody guessed is real.
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

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function execute(?ProductKind $kind = null, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        // Clamped, not rejected: a caller asking for more than the ceiling
        // gets the ceiling. Zero and negatives are a nonsense page rather than
        // a small one, so they fall back to the default instead of to 1.
        $perPage = $perPage < 1 ? self::DEFAULT_PER_PAGE : min($perPage, self::MAX_PER_PAGE);

        return Product::query()
            ->purchasable()
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
