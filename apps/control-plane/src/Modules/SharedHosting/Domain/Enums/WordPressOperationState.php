<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Enums;

/**
 * Where a copy or a push has got to. `indeterminate` is the Timeout Rule:
 * the toolkit did not answer, the copy may exist or production may be
 * half-overwritten, and nothing here tries again.
 */
enum WordPressOperationState: string
{
    case Requested = 'requested';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Indeterminate = 'indeterminate';

    public function isInFlight(): bool
    {
        return $this === self::Requested || $this === self::Running;
    }

    public function needsAttention(): bool
    {
        return $this === self::Indeterminate;
    }
}
