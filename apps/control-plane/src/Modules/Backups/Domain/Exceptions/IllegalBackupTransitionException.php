<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Exceptions;

use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Something tried to move a backup row somewhere it cannot go.
 *
 * Raised rather than logged, because every one of these is a bug in the
 * platform and the alternative is a row that quietly went backwards. The two
 * that matter most: nothing may reach `Succeeded` except from a provider task
 * that reported OK, and nothing leaves `Failed` — a failed backup is not
 * repaired, a new one is taken, so the failure stays in the history where a
 * customer asking "when did this last work?" can see it.
 */
final class IllegalBackupTransitionException extends DomainException
{
    public static function between(string $backupId, BackupState $from, BackupState $to): self
    {
        $exception = new self(sprintf(
            'A backup cannot move from %s to %s.',
            $from->value,
            $to->value,
        ));

        return $exception->withContext([
            'backup_id' => $backupId,
            'from' => $from->value,
            'to' => $to->value,
        ]);
    }

    public function errorCode(): string
    {
        return 'backups.illegal_transition';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
