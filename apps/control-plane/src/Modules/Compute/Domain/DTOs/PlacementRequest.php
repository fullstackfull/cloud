<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

use Lynomia\Modules\Compute\Domain\Enums\CpuArchitecture;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\ValueObjects\VmResources;

/**
 * What the scheduler is being asked to place, and for whom.
 *
 * The customer is part of the request rather than an optional hint because
 * anti-affinity is meaningless without it: a scheduler that does not know who
 * is buying cannot avoid putting a customer's whole redundant cluster on one
 * node, which is the failure that makes redundancy worthless.
 *
 * @immutable
 */
final readonly class PlacementRequest
{
    /**
     * @param  list<string>  $affinityNodeIds  Restricts placement to these nodes — a licence tied to a
     *                                         socket, or a machine that must sit beside its pair.
     * @param  list<string>  $excludedNodeIds  Nodes a retry already failed on.
     */
    public function __construct(
        public string $clusterId,
        public VmResources $resources,
        public ?string $customerId = null,
        public StorageClass $storageClass = StorageClass::Nvme,
        public CpuArchitecture $architecture = CpuArchitecture::X86_64,
        public array $affinityNodeIds = [],
        public array $excludedNodeIds = [],
    ) {}
}
