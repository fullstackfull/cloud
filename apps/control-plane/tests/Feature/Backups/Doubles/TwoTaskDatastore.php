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
 * A datastore that can tell two tasks apart, and insists that the caller can.
 *
 * ---------------------------------------------------------------------------
 * Why not the shipped simulator
 * ---------------------------------------------------------------------------
 *
 * The Round 1 audit found fakes in this repository that model the adapter's
 * mistake rather than the provider's contract, which makes the corresponding
 * bug untestable: a simulator that answers the same thing for every task id
 * cannot fail a test that polls the wrong one.
 *
 * So this double holds an explicit map of task id to outcome and nothing else.
 * It has no notion of a backup, a verification or a restore — it answers about
 * the task it is asked about, and it throws for a task it was never given, the
 * way a real provider does for a handle it has never issued. A test therefore
 * sets up a *finished, successful* backup task and a *still running* restore
 * task, and the difference between the two is the whole oracle. Nothing here
 * depends on which column the platform reads, which is the point.
 *
 * `startRestore` issues a task id the caller chooses in advance, and every
 * call is recorded, so a test can prove that two requests produced one
 * provider operation rather than two.
 */
final class TwoTaskDatastore implements BackupProvider
{
    /** @var list<array{node: string, providerId: string, archiveId: string}> */
    public array $restoresStarted = [];

    /** @var list<string> */
    public array $tasksAsked = [];

    /**
     * @param  array<string, BackupTaskState>  $tasks  task id => what the provider says about it
     * @param  string  $nextRestoreTaskId  the handle startRestore will hand back
     */
    public function __construct(
        private array $tasks,
        private readonly string $nextRestoreTaskId = 'UPID:two-task:restore',
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function startBackup(BackupRequest $request): BackupOperation
    {
        throw BackupProviderException::refused('fake', 'backup_create', 'this double does not take backups', []);
    }

    public function taskState(string $nodeName, string $taskId): BackupTaskState
    {
        $this->tasksAsked[] = $taskId;

        $state = $this->tasks[$taskId] ?? null;

        if ($state === null) {
            /*
             * The behaviour that makes this double useful. A provider has
             * never heard of a handle it did not issue, and says so; it does
             * not quietly answer about some other task. A platform that polls
             * the wrong identifier therefore gets an error rather than a
             * plausible-looking verdict about somebody else's work.
             */
            throw BackupProviderException::refused(
                'fake',
                'backup_task_status',
                'no such task',
                ['task_id' => $taskId],
            );
        }

        return $state;
    }

    /**
     * Replace what the provider says about one task, so a test can let a
     * restore finish between two polls without rebuilding the world.
     */
    public function settle(string $taskId, BackupTaskState $state): void
    {
        $this->tasks[$taskId] = $state;
    }

    public function supportsVerification(): bool
    {
        return false;
    }

    public function startVerification(string $nodeName, string $datastore, string $archiveId): BackupOperation
    {
        throw BackupProviderException::refused('fake', 'backup_verify', 'this double does not verify', []);
    }

    public function startRestore(string $nodeName, string $providerId, string $datastore, string $archiveId): BackupOperation
    {
        $this->restoresStarted[] = ['node' => $nodeName, 'providerId' => $providerId, 'archiveId' => $archiveId];

        return new BackupOperation(taskId: $this->nextRestoreTaskId, nodeName: $nodeName);
    }

    /**
     * @return list<RemoteBackup>
     */
    public function listBackups(string $nodeName, string $datastore, string $providerId): array
    {
        return [];
    }

    public function deleteBackup(string $nodeName, string $datastore, string $archiveId): void
    {
        throw BackupProviderException::refused('fake', 'backup_delete', 'this double does not delete', []);
    }
}
