<?php

declare(strict_types=1);

namespace Tests\Feature\Backups\Doubles;

use Lynomia\Modules\Backups\Domain\Contracts\BackupProvider;
use Lynomia\Modules\Backups\Domain\DTOs\BackupOperation;
use Lynomia\Modules\Backups\Domain\DTOs\BackupRequest;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\DTOs\RemoteBackup;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;

/**
 * A datastore that accepts a deletion and keeps the archive anyway.
 *
 * This is the situation the whole deletion design exists for and it is not
 * hypothetical: a prune queued behind a running verification, a read-only
 * datastore, a chunk store mid-garbage-collection. The provider takes the
 * instruction, returns without complaint, and the file is still there.
 *
 * A platform that trusted acceptance would tell the customer their data was
 * destroyed. This double is how that is proved not to happen.
 */
final class StubbornBackupProvider implements BackupProvider
{
    /** @var list<RemoteBackup> */
    private array $stored;

    public int $deletionsAccepted = 0;

    public function __construct(string ...$archiveIds)
    {
        $this->stored = array_map(
            static fn (string $id): RemoteBackup => new RemoteBackup(
                archiveId: $id,
                datastore: 'pbs-test',
                sizeBytes: 1_073_741_824,
                createdAt: 1_700_000_000,
                verified: null,
                notes: null,
            ),
            $archiveIds,
        );
    }

    public function name(): string
    {
        return 'fake';
    }

    public function startBackup(BackupRequest $request): BackupOperation
    {
        throw BackupProviderException::refused('fake', 'backup_create', 'this double only deletes', []);
    }

    public function taskState(string $nodeName, string $taskId): BackupTaskState
    {
        throw BackupProviderException::refused('fake', 'backup_task_status', 'this double only deletes', []);
    }

    public function supportsVerification(): bool
    {
        return false;
    }

    public function startVerification(string $nodeName, string $datastore, string $archiveId): BackupOperation
    {
        throw BackupProviderException::refused('fake', 'backup_verify', 'this double only deletes', []);
    }

    public function startRestore(string $nodeName, string $providerId, string $datastore, string $archiveId): BackupOperation
    {
        throw BackupProviderException::refused('fake', 'backup_restore', 'this double only deletes', []);
    }

    /**
     * @return list<RemoteBackup>
     */
    public function listBackups(string $nodeName, string $datastore, string $providerId): array
    {
        return $this->stored;
    }

    /**
     * Accepts, and does nothing. Exactly what a queued prune looks like from
     * the outside.
     */
    public function deleteBackup(string $nodeName, string $datastore, string $archiveId): void
    {
        $this->deletionsAccepted++;
    }
}
