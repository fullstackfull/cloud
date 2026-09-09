<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * What a push to production overwrites. Named, because "push" alone hides
 * the fact that a database push takes with it every post and order the
 * production site received since the copy was made.
 */
enum WordPressPushScope: string
{
    case Files = 'files';
    case Database = 'database';
    case Both = 'both';

    public function overwritesDatabase(): bool
    {
        return $this !== self::Files;
    }
}
