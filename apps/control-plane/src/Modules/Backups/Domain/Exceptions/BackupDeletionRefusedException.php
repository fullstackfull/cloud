<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Exceptions;

use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A deletion the platform will not start.
 *
 * Every one of these is a case where removing the archive would either destroy
 * something somebody is in the middle of using, or destroy the only copy of
 * something the platform promised to keep. None of them is "you may not"; they
 * are all "not this one, or not yet".
 */
final class BackupDeletionRefusedException extends DomainException
{
    private string $errorCode = 'backup.deletion_refused';

    private int $status = 409;

    public static function becauseItIsNotAvailable(string $backupId, BackupState $state): self
    {
        return (new self('This backup is not in a state that can be deleted.'))
            ->withContext(['backup_id' => $backupId, 'state' => $state->value])
            ->as('backup.not_deletable');
    }

    public static function becauseItIsAlreadyGoing(string $backupId): self
    {
        return (new self('This backup is already being deleted.'))
            ->withContext(['backup_id' => $backupId])
            ->as('backup.deletion_already_requested');
    }

    /**
     * A restore reads the archive it is restoring from. Deleting it mid-restore
     * leaves a machine half-written from a source that no longer exists, which
     * is a worse outcome than either finishing or failing.
     */
    public static function becauseARestoreIsRunning(string $backupId): self
    {
        return (new self('A restore is running from this backup. It cannot be deleted until that finishes.'))
            ->withContext(['backup_id' => $backupId])
            ->as('backup.restore_in_progress');
    }

    public static function becauseItIsStillBeingWritten(string $backupId, BackupState $state): self
    {
        return (new self('This backup has not finished yet.'))
            ->withContext(['backup_id' => $backupId, 'state' => $state->value])
            ->as('backup.still_in_flight');
    }

    public static function becauseThePlanForbidsIt(string $backupId): self
    {
        return (new self('Backups on this plan are kept for their full retention period and cannot be deleted early.'))
            ->withContext(['backup_id' => $backupId])
            ->as('backup.deletion_not_permitted')
            ->withStatus(403);
    }

    public static function becauseItIsProtected(string $backupId, string $until): self
    {
        return (new self('This backup is held until the retention period on the ended service expires.'))
            ->withContext(['backup_id' => $backupId, 'protected_until' => $until])
            ->as('backup.protected');
    }

    public static function becauseTheConfirmationDoesNotMatch(): self
    {
        return (new self('Type the backup reference to confirm that this copy is to be destroyed.'))
            ->as('backup.deletion_not_confirmed')
            ->withStatus(422);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    private function as(string $code): self
    {
        $this->errorCode = $code;

        return $this;
    }

    private function withStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }
}
