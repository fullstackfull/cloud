<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Enums;

/**
 * Where a file-level restore has got to.
 *
 * The same shape as a whole-machine restore, kept on its own row because a
 * file restore does not take the backup out of service — the archive is
 * read, not consumed — and two customers' questions ("is my backup still
 * good?" and "did my files come back?") must not share one state column.
 *
 * `needs_review` is the Timeout Rule: a restore the provider did not answer
 * for may be writing into the machine right now, and is never retried.
 */
enum FileRestoreState: string
{
    case Requested = 'requested';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case NeedsReview = 'needs_review';

    public function isInFlight(): bool
    {
        return $this === self::Requested || $this === self::Running;
    }

    public function needsAttention(): bool
    {
        return $this === self::NeedsReview;
    }
}
