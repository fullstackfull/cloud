<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\ValueObjects;

use Lynomia\Modules\Compute\Domain\Enums\PlacementRejectionReason;

/**
 * Whether one node can take one machine, and what is left if it does.
 *
 * The post-placement figures are carried alongside the verdict because both
 * callers need them: the scheduler scores on the memory that remains *after*
 * this machine lands — the constraint that actually binds — and the reservation
 * writes the same numbers into its audit context so that a capacity dispute
 * can be settled from the record rather than re-derived.
 *
 * @immutable
 */
final readonly class CapacityAssessment
{
    private function __construct(
        public bool $fits,
        public ?PlacementRejectionReason $reason,
        public ?string $detail,
        public int $freeMemoryMibAfter,
        public int $freeCpuCoresAfter,
        public int $freeStorageGibAfter,
        public int $schedulableMemoryMib,
        public int $schedulableCpuCores,
        public int $totalStorageGib,
    ) {}

    public static function fits(
        int $freeMemoryMibAfter,
        int $freeCpuCoresAfter,
        int $freeStorageGibAfter,
        int $schedulableMemoryMib,
        int $schedulableCpuCores,
        int $totalStorageGib,
    ): self {
        return new self(
            true,
            null,
            null,
            $freeMemoryMibAfter,
            $freeCpuCoresAfter,
            $freeStorageGibAfter,
            $schedulableMemoryMib,
            $schedulableCpuCores,
            $totalStorageGib,
        );
    }

    public static function rejected(
        PlacementRejectionReason $reason,
        string $detail,
        int $freeMemoryMibAfter = 0,
        int $freeCpuCoresAfter = 0,
        int $freeStorageGibAfter = 0,
        int $schedulableMemoryMib = 0,
        int $schedulableCpuCores = 0,
        int $totalStorageGib = 0,
    ): self {
        return new self(
            false,
            $reason,
            $detail,
            $freeMemoryMibAfter,
            $freeCpuCoresAfter,
            $freeStorageGibAfter,
            $schedulableMemoryMib,
            $schedulableCpuCores,
            $totalStorageGib,
        );
    }

    /**
     * The share of schedulable memory still free once this machine has landed,
     * as a fraction between 0 and 1.
     */
    public function memoryHeadroomRatio(): float
    {
        return $this->schedulableMemoryMib < 1
            ? 0.0
            : max(0.0, min(1.0, $this->freeMemoryMibAfter / $this->schedulableMemoryMib));
    }

    public function cpuHeadroomRatio(): float
    {
        return $this->schedulableCpuCores < 1
            ? 0.0
            : max(0.0, min(1.0, $this->freeCpuCoresAfter / $this->schedulableCpuCores));
    }

    public function storageHeadroomRatio(): float
    {
        return $this->totalStorageGib < 1
            ? 0.0
            : max(0.0, min(1.0, $this->freeStorageGibAfter / $this->totalStorageGib));
    }
}
