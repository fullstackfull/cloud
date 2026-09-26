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
    private bool $raced = false;

    /**
     * The row is no longer in the state this copy of it was read in.
     *
     * Not a bug in the caller's reasoning but in its timing: somebody else —
     * a customer's restore, a deletion request, an operator's verdict — moved
     * the row after it was read. Nothing was written. A sweep skips the row
     * and meets it again next time; a request refuses. Writing anyway is how
     * the verification sweep used to put `verifying` over a running restore.
     */
    public static function movedUnderneath(string $backupId, BackupState $expected, ?string $actual, BackupState $to): self
    {
        $exception = new self(sprintf(
            'A backup read as %s is now %s; it was not moved to %s.',
            $expected->value,
            $actual ?? 'missing',
            $to->value,
        ));
        $exception->raced = true;

        return $exception->withContext([
            'backup_id' => $backupId,
            'from' => $expected->value,
            'actual' => $actual,
            'to' => $to->value,
        ]);
    }

    public function wasRaced(): bool
    {
        return $this->raced;
    }

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
