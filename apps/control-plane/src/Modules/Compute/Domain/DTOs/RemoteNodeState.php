<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

/**
 * A hypervisor node as the cluster reports it.
 *
 * Everything here is physical fact — how much memory the box has, how much of
 * it the hypervisor is using right now. Notably absent is how much the
 * platform has *committed* to machines it has placed: the hypervisor does not
 * know about a VM that is still being built, or one whose capacity is held for
 * an order that has not shipped. That number is the platform's own bookkeeping
 * and an inventory sync must never overwrite it.
 *
 * @immutable
 */
final readonly class RemoteNodeState
{
    /**
     * @param  float|null  $cpuUsage  Fraction between 0 and 1, as reported.
     * @param  list<RemoteStorageState>  $storages
     * @param  array<string, mixed>  $capabilities  Redacted provider detail: version, kernel, cpu model.
     */
    public function __construct(
        public string $name,
        public bool $online,
        public int $cpuCores,
        public int $memoryTotalMib,
        public ?int $memoryUsedMib = null,
        public ?float $cpuUsage = null,
        public ?int $storageTotalGib = null,
        public ?int $storageAvailableGib = null,
        public array $storages = [],
        public array $capabilities = [],
    ) {}
}
