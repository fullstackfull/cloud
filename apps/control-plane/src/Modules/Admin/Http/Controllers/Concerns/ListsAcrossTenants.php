<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The administrative surface reads across every customer, deliberately.
 *
 * That is the whole difference between this and `/api/v1`, and it is why no
 * route here carries the acting-customer middleware: an operator answering a
 * ticket needs to see the account that raised it, not the one they happen to
 * belong to. What replaces the tenant scope is the permission on each route,
 * which a test refuses to let anybody forget.
 */
trait ListsAcrossTenants
{
    public const int MAX_PER_PAGE = 100;

    public const int DEFAULT_PER_PAGE = 25;

    /**
     * A nonsense page size is treated as unspecified rather than refused, the
     * same rule the customer surface follows. The ceiling is what matters and
     * it holds whatever arrives.
     */
    protected function perPage(Request $request): int
    {
        $requested = $request->integer('per_page', self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            $requested = self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    /**
     * @param  LengthAwarePaginator<int, covariant object>  $page
     * @param  callable(mixed): mixed  $resource
     */
    protected function paginated(LengthAwarePaginator $page, callable $resource): JsonResponse
    {
        return response()->json([
            'data' => array_map($resource, $page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'max_per_page' => self::MAX_PER_PAGE,
            ],
        ]);
    }
}
