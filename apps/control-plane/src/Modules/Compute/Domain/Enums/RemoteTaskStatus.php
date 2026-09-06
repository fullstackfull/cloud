<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Enums;

/**
 * The outcome of an asynchronous hypervisor task.
 *
 * Running is not a failure and must never be treated as one: a create that is
 * still running has probably already allocated a VMID and a disk, so a caller
 * that gives up and retries builds a second machine the first customer will
 * never see but the platform will keep paying for.
 */
enum RemoteTaskStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';

    public function isFinished(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }
}
