<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /**
     * Whether the account may place new orders or provision new resources.
     * A suspended account keeps its existing services visible but cannot grow.
     */
    public function canPurchase(): bool
    {
        return $this === self::Active;
    }
}
