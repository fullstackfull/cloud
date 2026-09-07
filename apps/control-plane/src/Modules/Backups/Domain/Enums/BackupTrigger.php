<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Enums;

/**
 * Who asked for this backup.
 *
 * Kept because the two are answerable to different people. A scheduled backup
 * that has not run is the platform's failure and belongs on an operator's
 * dashboard; a manual one that failed is the customer's question, and they are
 * waiting for an answer to it now.
 *
 * It also bounds retention differently: a customer's manual backup before a
 * risky change is theirs to keep until they say otherwise, while the scheduled
 * series is pruned on the policy the plan sold them.
 */
enum BackupTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';

    /**
     * Whether somebody is waiting for this to finish right now.
     */
    public function hasSomebodyWaiting(): bool
    {
        return $this === self::Manual;
    }
}
