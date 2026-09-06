<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Enums;

/**
 * What a pool's addresses are routed for.
 *
 * The scope decides who may ever be given one: management addresses reach the
 * hypervisor and BMC control planes, and handing one to a customer workload is
 * a lateral-movement path into the platform itself.
 */
enum IpPoolScope: string
{
    case Public = 'public';
    case Private = 'private';
    case Management = 'management';

    /** Whether addresses from this pool may be assigned to customer services. */
    public function isCustomerAllocatable(): bool
    {
        return $this !== self::Management;
    }

    /**
     * Whether an address from this pool carries reputation off-platform.
     * Only publicly routed addresses appear in DNS blocklists and abuse
     * reports, which is what quarantine exists to absorb.
     */
    public function isInternetRouted(): bool
    {
        return $this === self::Public;
    }
}
