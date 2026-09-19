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
 * A datastore that verifies on its own schedule and will not be told when.
 *
 * Shaped like Proxmox Backup Server, which is the only backup provider in
 * this repository that can run in production. It refuses to start a
 * verification — the hypervisor API has no endpoint for it — and it reports
 * the verdict of the verifications it ran by itself, per archive, in the
 * listing.
 *
 * The controlled simulator does the opposite: it accepts a verification and
 * settles it into a verdict, because that is the path that had to be
 * exercised. Between the two, both halves of the contract are covered — and
 * this double is the half that matters in production.
 */
final class SelfVerifyingDatastore implements BackupProvider
{
    public int $verificationsAttempted = 0;

    /**
     * @param  array<string, ?bool>  $verdicts  archive id => read back cleanly, failed, or not yet checked
     */
    public function __construct(private readonly array $verdicts) {}

    public function name(): string
    {
        return 'fake';
    }

    public function startBackup(BackupRequest $request): BackupOperation
    {
        throw BackupProviderException::refused('fake', 'backup_create', 'this double only lists and verifies', []);
    }

    public function taskState(string $nodeName, string $taskId): BackupTaskState
    {
        throw BackupProviderException::refused('fake', 'backup_task_status', 'this double only lists and verifies', []);
    }

    public function supportsVerification(): bool
    {
        return false;
    }

    /**
     * Counted, so a test can prove the sweep never got this far.
     */
    public function startVerification(string $nodeName, string $datastore, string $archiveId): BackupOperation
    {
        $this->verificationsAttempted++;

        throw BackupProviderException::refused(
            'fake',
            'backup_verify',
            'this datastore verifies on its own schedule and cannot be asked to start one',
            [],
        );
    }

    public function startRestore(string $nodeName, string $providerId, string $datastore, string $archiveId): BackupOperation
    {
        throw BackupProviderException::refused('fake', 'backup_restore', 'this double only lists and verifies', []);
    }

    /**
     * @return list<RemoteBackup>
     */
    public function listBackups(string $nodeName, string $datastore, string $providerId): array
    {
        $listed = [];

        foreach ($this->verdicts as $archiveId => $verified) {
            $listed[] = new RemoteBackup(
                archiveId: (string) $archiveId,
                datastore: $datastore,
                sizeBytes: 1_073_741_824,
                createdAt: 1_700_000_000,
                verified: $verified,
                notes: null,
            );
        }

        return $listed;
    }

    public function deleteBackup(string $nodeName, string $datastore, string $archiveId): void
    {
        throw BackupProviderException::refused('fake', 'backup_delete', 'this double only lists and verifies', []);
    }
}
