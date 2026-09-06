<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

use Lynomia\Modules\Compute\Domain\Enums\StorageClass;

/**
 * A storage pool as the hypervisor reports it.
 *
 * The class is nullable because a hypervisor has no notion of what the
 * platform sells: Proxmox knows a pool is called "nvme-01" and is of type
 * lvmthin, not that it backs the NVMe plan. When the adapter cannot infer the
 * class it says so, and the sync leaves the operator-set class alone rather
 * than overwriting a commercial decision with a guess.
 *
 * @immutable
 */
final readonly class RemoteStorageState
{
    public function __construct(
        public string $name,
        public bool $shared = false,
        public ?StorageClass $storageClass = null,
        public ?int $totalGib = null,
        public ?int $availableGib = null,
        public bool $active = true,
    ) {}
}
