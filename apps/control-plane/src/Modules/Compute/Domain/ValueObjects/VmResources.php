<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * The shape of one machine: the three quantities every placement decision is
 * made against.
 *
 * A value object rather than three loose integers because the arguments are
 * interchangeable at the type level and transposing memory and disk produces a
 * placement that succeeds, provisions, and is wrong. The constructor also
 * refuses zero and negative values, which would otherwise let a machine be
 * "placed" on a full node because it asks for nothing.
 *
 * @immutable
 */
final readonly class VmResources
{
    public function __construct(
        public int $vcpu,
        public int $memoryMib,
        public int $diskGib,
    ) {
        if ($vcpu < 1 || $memoryMib < 1 || $diskGib < 1) {
            throw new InvalidArgumentException(sprintf(
                'A machine must ask for at least one of each resource; got %d vCPU, %d MiB, %d GiB.',
                $vcpu,
                $memoryMib,
                $diskGib,
            ));
        }
    }

    /**
     * Build from a service's snapshotted resources column.
     *
     * Services store what was bought as a plain array so that a later plan edit
     * cannot resize a running machine; this is the one place that array is
     * turned back into something the scheduler can reason about.
     *
     * @param  array<string, mixed>  $resources
     */
    public static function fromArray(array $resources): self
    {
        return new self(
            vcpu: (int) ($resources['vcpu'] ?? 0),
            memoryMib: (int) ($resources['memory_mib'] ?? 0),
            diskGib: (int) ($resources['disk_gib'] ?? 0),
        );
    }

    /**
     * @return array{vcpu: int, memory_mib: int, disk_gib: int}
     */
    public function toArray(): array
    {
        return [
            'vcpu' => $this->vcpu,
            'memory_mib' => $this->memoryMib,
            'disk_gib' => $this->diskGib,
        ];
    }
}
