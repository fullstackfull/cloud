<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Enums;

enum CustomerType: string
{
    case Individual = 'individual';
    case Organization = 'organization';
}
