<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Enums;

enum NetworkPurpose: string
{
    case Public = 'public';
    case Private = 'private';
    case Storage = 'storage';
    case Management = 'management';
    case ClusterInterconnect = 'cluster_interconnect';

    /** Whether a customer VM may be attached to this network. */
    public function isCustomerAttachable(): bool
    {
        return match ($this) {
            self::Public, self::Private => true,
            default => false,
        };
    }
}
