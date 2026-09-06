<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Enums;

enum ReverseDnsStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
    case Removing = 'removing';

    public function isSettled(): bool
    {
        return match ($this) {
            self::Active, self::Failed => true,
            default => false,
        };
    }
}
