<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * What a site row is. A staging copy belongs to a production site and is
 * the only kind that can be pushed back; a clone is a production site in
 * its own right that happened to start as a copy.
 */
enum WordPressSiteKind: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Clone = 'clone';

    public function canBePushedToProduction(): bool
    {
        return $this === self::Staging;
    }

    public function canBeCopied(): bool
    {
        return $this !== self::Staging;
    }
}
