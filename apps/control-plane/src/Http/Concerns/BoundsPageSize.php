<?php

declare(strict_types=1);

namespace Lynomia\Http\Concerns;

/**
 * One answer to "how many rows may a single request return".
 *
 * A nonsense page size - zero, negative - is treated as unspecified rather than
 * refused. A client that sends an unset paging control as a zero is asking for
 * the default page, and answering 422 to that turns a harmless client quirk into
 * a broken integration; the ceiling is what this exists to enforce, and it is
 * enforced whatever arrives.
 *
 * Applied by the form request, but the ceiling must also hold for a caller that
 * is not HTTP - a console command, a queue job, a future admin surface. Actions
 * that paginate clamp against this same constant rather than trusting whatever
 * they are handed.
 */
trait BoundsPageSize
{
    public const int MAX_PER_PAGE = 100;

    public const int DEFAULT_PER_PAGE = 25;

    public function perPage(): int
    {
        $requested = $this->integer('per_page', self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            $requested = self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }
}
