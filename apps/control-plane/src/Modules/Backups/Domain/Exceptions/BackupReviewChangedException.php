<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A verdict on a review that is no longer the one waiting.
 *
 * An operator reads the review list, goes to the provider, and hours later
 * says what the task did. Between the two the review they read can have been
 * settled and the archive restored again, and that second restore lost in
 * its turn: the row is in review once more, for an attempt the operator never
 * looked at. Their verdict is about the first. It is refused, and they read
 * the list again.
 */
final class BackupReviewChangedException extends DomainException
{
    public static function forBackup(string $backupId): self
    {
        return (new self('This review has changed since it was read. Read it again before deciding.'))
            ->withContext(['backup_id' => $backupId]);
    }

    public function errorCode(): string
    {
        return 'backup.review_changed';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
