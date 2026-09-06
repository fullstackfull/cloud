<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

enum DriftStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    /**
     * Whether a fresh sighting belongs on this row.
     *
     * A resolved drift that reappears is news, not a repeat: it starts a new
     * row so the resolution that did not hold stays visible in history.
     */
    public function absorbsNewSightings(): bool
    {
        return match ($this) {
            self::Open, self::Acknowledged => true,
            self::Resolved => false,
        };
    }
}
