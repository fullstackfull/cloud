<?php

declare(strict_types=1);

namespace Lynomia\Modules\Activity\Application\DTOs;

/**
 * One page of activity, and the cursor that follows it.
 *
 * There is no total. Activity is unbounded history, and counting it would mean
 * a second pass over every branch on every page to produce a number that is
 * stale before it is rendered and that nothing on the screen needs. The page
 * knows whether there is more, which is the only question a "load more"
 * control asks.
 *
 * @immutable
 */
final readonly class ActivityPage
{
    /**
     * @param  list<ActivityItem>  $items
     * @param  ?string  $nextCursor  Opaque; null when this is the last page.
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {}
}
