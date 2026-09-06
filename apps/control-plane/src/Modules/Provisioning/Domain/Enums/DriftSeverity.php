<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

enum DriftSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * Ordering for "has this got worse since we last saw it", so a drift that
     * escalates is never quietly downgraded by a later sighting.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->weight() >= $other->weight();
    }
}
