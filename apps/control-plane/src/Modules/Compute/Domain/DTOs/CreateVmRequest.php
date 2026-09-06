<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

use Lynomia\Modules\Compute\Domain\Enums\OsFamily;

/**
 * Everything a hypervisor needs to build one machine.
 *
 * The node and the numeric id are inputs rather than provider choices. The
 * platform picks the node (see NodeScheduler) because only it knows what the
 * customer already has and what the fleet is being drained for, and it picks
 * the id because the id is written into the local row before the call is made
 * — a machine the provider named after the fact is a machine we cannot find
 * again if the response is lost.
 *
 * @immutable
 */
final readonly class CreateVmRequest
{
    /**
     * @param  string  $nodeName  The hypervisor node's own name, not the platform's node id.
     * @param  int  $vmId  The hypervisor's numeric machine id, chosen by the platform.
     * @param  string|null  $templateReference  The image or template the disk is imported from.
     * @param  array<string, string>  $tags  Free-form labels echoed to the hypervisor for operators.
     */
    public function __construct(
        public string $nodeName,
        public int $vmId,
        public string $hostname,
        public int $vcpu,
        public int $memoryMib,
        public int $diskGib,
        public string $storageName,
        public ?string $templateReference = null,
        public OsFamily $osFamily = OsFamily::Debian,
        public string $networkBridge = 'vmbr0',
        public ?int $vlanTag = null,
        public ?int $networkRateMbps = null,
        public ?CloudInitConfig $cloudInit = null,
        public bool $startAfterCreate = true,
        public array $tags = [],
        public ?string $description = null,
    ) {}
}
