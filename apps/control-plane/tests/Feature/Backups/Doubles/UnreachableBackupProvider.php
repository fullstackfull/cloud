<?php

declare(strict_types=1);

namespace Tests\Feature\Backups\Doubles;

use Lynomia\Modules\Backups\Domain\Contracts\BackupProvider;
use Lynomia\Modules\Backups\Domain\DTOs\BackupOperation;
use Lynomia\Modules\Backups\Domain\DTOs\BackupRequest;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;

/**
 * A provider that has stopped answering.
 *
 * Every method times out, which is the condition a poller has to survive: a
 * datastore under load answers slowly or not at all for minutes at a time, and
 * a platform that turned each unanswered read into a state change would mark
 * good backups as needing review every time a verification job ran.
 *
 * Written as a class rather than as an anonymous subclass of the fake, because
 * the fake is final — deliberately, so that a test double cannot inherit half
 * of a fake's behaviour and quietly diverge from the interface.
 */
final class UnreachableBackupProvider implements BackupProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function supportsVerification(): bool
    {
        return true;
    }

    public function startBackup(BackupRequest $request): BackupOperation
    {
        throw BackupProviderException::timedOut($this->name(), 'start_backup');
    }

    public function taskState(string $nodeName, string $taskId): BackupTaskState
    {
        throw BackupProviderException::timedOut($this->name(), 'backup_task_status');
    }

    public function startVerification(string $nodeName, string $datastore, string $archiveId): BackupOperation
    {
        throw BackupProviderException::timedOut($this->name(), 'start_verification');
    }

    public function startRestore(string $nodeName, string $providerId, string $datastore, string $archiveId): BackupOperation
    {
        throw BackupProviderException::timedOut($this->name(), 'start_restore');
    }

    public function listBackups(string $nodeName, string $datastore, string $providerId): array
    {
        throw BackupProviderException::timedOut($this->name(), 'list_backups');
    }

    public function deleteBackup(string $nodeName, string $datastore, string $archiveId): void
    {
        throw BackupProviderException::timedOut($this->name(), 'delete_backup');
    }
}
