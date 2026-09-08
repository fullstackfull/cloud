<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Enums;

/**
 * How the machine is treated while its disks are read.
 *
 * The three are a genuine trade and the platform does not get to pretend
 * otherwise:
 *
 *  - **snapshot** — the machine keeps running and the backup is taken from a
 *    point-in-time snapshot. Filesystem-consistent only as far as the guest
 *    made it so, which for a database mid-write means "not". It is the default
 *    because the alternative is downtime a customer did not ask for.
 *  - **suspend** — the machine is paused for the copy. Shorter than a stop and
 *    still visible to anybody using it.
 *  - **stop** — the machine is shut down, backed up, and started again. The
 *    only one that is unambiguously consistent, and the only one that costs a
 *    customer an outage.
 */
enum BackupMode: string
{
    case Snapshot = 'snapshot';
    case Suspend = 'suspend';
    case Stop = 'stop';

    /**
     * Whether choosing this interrupts whoever is using the machine.
     */
    public function interruptsService(): bool
    {
        return $this !== self::Snapshot;
    }
}
