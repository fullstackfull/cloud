<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

enum WordPressOperationKind: string
{
    case CreateStaging = 'create_staging';
    case Clone = 'clone';
    case PushToProduction = 'push_to_production';

    /** Whether the operation writes over something a customer already has. */
    public function isDestructive(): bool
    {
        return $this === self::PushToProduction;
    }
}
