<?php

declare(strict_types=1);

namespace Lynomia\Modules\Billing\Http\Requests\Concerns;

/**
 * The page-size ceiling every collection endpoint in this module shares.
 *
 * The bound is applied by clamping rather than by refusing. A client asking
 * for 100000 rows means "as many as I can have", and answering with 100 is
 * more useful than a 422 — while the clamp, not the validation rule, is what
 * guarantees the query can never be handed a larger number.
 */
trait BoundsPageSize
{
    /** Nobody gets more than this in one page, whatever they ask for. */
    public const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 25;

    public function perPage(): int
    {
        $requested = $this->integer('per_page', self::DEFAULT_PER_PAGE);

        // Zero and negatives are not a smaller page, they are a nonsense page.
        // Treated as "unspecified" rather than clamped to 1, which would make
        // ?per_page=0 a slow way to walk the whole collection.
        if ($requested < 1) {
            $requested = self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }
}
