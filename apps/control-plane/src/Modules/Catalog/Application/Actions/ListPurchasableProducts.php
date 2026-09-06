<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Application\Actions;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Lynomia\Modules\Catalog\Domain\Enums\ProductKind;
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
 * @phpstan-type ProductPaginator LengthAwarePaginator<int, Product>
 */
final class ListPurchasableProducts
{
    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function execute(?ProductKind $kind = null, int $perPage = 25): LengthAwarePaginator
    {
        return Product::query()
            ->purchasable()
            ->when($kind !== null, static fn (Builder $query): Builder => $query->where('kind', $kind?->value))
            ->withCount(['plans' => static fn (Builder $query): Builder => $query->purchasable()])
            // sort_order is the merchandising decision; slug is the tiebreak
            // that keeps paging stable, because a page boundary that shifts
            // between requests silently drops rows from the second page.
            ->orderBy('sort_order')
            ->orderBy('slug')
            ->paginate($perPage);
    }
}
