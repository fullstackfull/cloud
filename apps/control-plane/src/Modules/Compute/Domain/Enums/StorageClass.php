<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Enums;

/**
 * The performance and durability class a disk is carved out of.
 *
 * The class is part of what the customer bought — an NVMe plan placed on
 * spinning disks is a refund — so it is a hard placement requirement rather
 * than a preference the scheduler may trade away for a better memory score.
 */
enum StorageClass: string
{
    case Nvme = 'nvme';
    case Ssd = 'ssd';
    case Hdd = 'hdd';
    case Ceph = 'ceph';

    /**
     * Whether this class normally lives on the node itself, which is what
     * decides whether a machine on it can be migrated without copying disks.
     */
    public function isNodeLocal(): bool
    {
        return $this !== self::Ceph;
    }
}
