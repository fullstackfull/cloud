<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Enums;

/**
 * Whether a GPU device is free to be handed out.
 *
 * `available` is the only state the readiness engine counts as capacity.
 * `reserved` is an operator holding it back; `allocated` is a guest holding
 * it; `faulted` is the host reporting it unusable. None of the last three is
 * capacity, and a row moves into them by an act, never by inference.
 */
enum GpuAllocationState: string
{
    case Available = 'available';
    case Allocated = 'allocated';
    case Reserved = 'reserved';
    case Faulted = 'faulted';

    public function isCapacity(): bool
    {
        return $this === self::Available;
    }
}
