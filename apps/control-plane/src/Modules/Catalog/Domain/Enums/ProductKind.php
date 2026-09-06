<?php

declare(strict_types=1);

namespace Lynomia\Modules\Catalog\Domain\Enums;

enum ProductKind: string
{
    case Vps = 'vps';
    case Dedicated = 'dedicated';
    case SharedHosting = 'shared_hosting';

    /**
     * Whether a plan of this kind can be provisioned automatically on payment,
     * or whether it needs an operator to allocate physical hardware first.
     */
    public function isAutomaticallyProvisioned(): bool
    {
        return match ($this) {
            self::Vps, self::SharedHosting => true,
            // A dedicated server needs a machine reserved from inventory; if
            // none is free the order waits rather than failing.
            self::Dedicated => false,
        };
    }
}
