<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Application\DTOs;

use Lynomia\Modules\Dedicated\Domain\Enums\ComponentHealth;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;

/**
 * What one read of one machine's controller changed in the platform's records.
 *
 * Returned rather than logged so that a scheduled sweep can report "eleven
 * machines, two newly degraded" without re-querying, and so a test can assert
 * on what a sync did rather than on what it printed.
 *
 * @immutable
 */
final readonly class HardwareInventorySyncResult
{
    /**
     * @param  int  $componentsMissing  Parts the platform has a row for that the controller no
     *                                  longer reports. Counted and never deleted: a drive that
     *                                  vanished has been pulled or has died, and both need a
     *                                  person.
     */
    public function __construct(
        public string $serverId,
        public ComponentHealth $health,
        public PowerState $powerState,
        public int $componentsReported = 0,
        public int $componentsCreated = 0,
        public int $componentsUpdated = 0,
        public int $componentsMissing = 0,
        public int $firmwareReported = 0,
    ) {}

    /** Whether this machine now needs a person to look at it. */
    public function needsAttention(): bool
    {
        return $this->health->needsAttention() || $this->componentsMissing > 0;
    }
}
