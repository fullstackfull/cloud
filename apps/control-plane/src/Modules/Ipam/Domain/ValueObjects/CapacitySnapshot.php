<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\ValueObjects;

use JsonSerializable;

/**
 * How much address space a pool or subnet has, and how long it will last.
 *
 * @immutable
 */
final readonly class CapacitySnapshot implements JsonSerializable
{
    public function __construct(
        public string $scopeType,
        public string $scopeId,
        public string $label,
        public int $total,
        public int $available,
        public int $reserved,
        public int $assigned,
        public int $quarantined,
        public int $unavailable,
        /** Addresses assigned per day, averaged over the observation window. */
        public float $allocationsPerDay,
        /** Days until `available` reaches zero, or null when nothing is being allocated. */
        public ?float $runwayDays,
        public int $observationDays,
    ) {}

    /**
     * Share of allocatable space currently spoken for, 0.0–1.0.
     *
     * Infrastructure addresses are excluded from the denominator: a /29 whose
     * five usable addresses are all assigned is 100% full, not 62%, and
     * reporting the smaller number is how a subnet runs out while the
     * dashboard still looks calm.
     */
    public function utilisation(): float
    {
        $allocatable = $this->total - $this->unavailable;

        if ($allocatable <= 0) {
            return 1.0;
        }

        return round(($this->reserved + $this->assigned) / $allocatable, 4);
    }

    /**
     * Whether an operator should be ordering address space now.
     *
     * The question is asked in days, not in percent, because a percentage is
     * not a warning. "10% free" is three months of comfort for a subnet taking
     * two orders a week and four days of panic for one taking a hundred — and
     * the second is exactly the subnet whose growth made the threshold
     * meaningless. Lead time for new address space is measured in weeks
     * (an RIR request, a justification, a routing change), so the threshold
     * that matters is "will this outlast the paperwork".
     */
    public function needsMoreSpace(int $leadTimeDays = 30): bool
    {
        if ($this->available === 0) {
            return true;
        }

        return $this->runwayDays !== null && $this->runwayDays <= $leadTimeDays;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeId,
            'label' => $this->label,
            'total' => $this->total,
            'available' => $this->available,
            'reserved' => $this->reserved,
            'assigned' => $this->assigned,
            'quarantined' => $this->quarantined,
            'unavailable' => $this->unavailable,
            'utilisation' => $this->utilisation(),
            'allocations_per_day' => $this->allocationsPerDay,
            'runway_days' => $this->runwayDays,
            'observation_days' => $this->observationDays,
        ];
    }
}
