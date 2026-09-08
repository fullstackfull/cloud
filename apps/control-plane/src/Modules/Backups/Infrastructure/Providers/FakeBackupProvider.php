<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Infrastructure\Providers;

use Lynomia\Modules\Backups\Domain\Contracts\BackupProvider;
use Lynomia\Modules\Backups\Domain\DTOs\BackupOperation;
use Lynomia\Modules\Backups\Domain\DTOs\BackupRequest;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\DTOs\RemoteBackup;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use RuntimeException;

/**
 * A backup provider that reaches no network and stores nothing.
 *
 * Its behaviour is a pure function of the notes it is given, so every path the
 * platform must handle can be reproduced without an HTTP stub: notes carrying
 * {@see self::REFUSAL_MARKER} are refused, {@see self::TIMEOUT_MARKER} makes
 * the call stop answering, and {@see self::FAILING_MARKER} produces a task
 * that starts and then fails — which is a different case from a refusal and
 * the one the reconciler exists for.
 *
 * It refuses to exist in production, on construction. Of every fake in this
 * platform this is the one whose presence in production would be worst: a fake
 * backup provider does not merely fail to back anything up, it reports backups
 * as taken and verified, and the discovery happens on the one day the customer
 * needs the data.
 */
final class FakeBackupProvider implements BackupProvider
{
    public const string NAME = 'fake';

    public const string REFUSAL_MARKER = 'backup-refused';

    public const string TIMEOUT_MARKER = 'backup-timeout';

    /** A task that is accepted, runs, and then fails. */
    public const string FAILING_MARKER = 'backup-fails';

    /** A credential-shaped string the refusal quotes back, so redaction is testable. */
    private const string CLUSTER_TOKEN = 'fake-pve-token-0123456789abcdef';

    /** @var array<string, array{failing: bool, polls: int, request: BackupRequest}> */
    private array $tasks = [];

    /** @var array<string, list<RemoteBackup>> datastore|vmid => backups */
    private array $stored = [];

    private int $nextId = 1;

    /**
     * How many times a task reports running before it settles. One by default,
     * so a reconciler that only ever polls once is visibly wrong.
     */
    public int $pollsBeforeSettling = 1;

    public function __construct()
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'The fake backup provider must never be constructed in production: '
                .'it reports backups as taken without taking them.'
            );
        }
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supportsVerification(): bool
    {
        return true;
    }

    public function startBackup(BackupRequest $request): BackupOperation
    {
        $this->refuseMarked($request->notes ?? '', 'start_backup');

        $taskId = 'UPID:fake:'.$this->nextId++;

        $this->tasks[$taskId] = [
            'failing' => str_contains($request->notes ?? '', self::FAILING_MARKER),
            'polls' => 0,
            'request' => $request,
        ];

        return new BackupOperation($taskId, $request->nodeName);
    }

    public function taskState(string $nodeName, string $taskId): BackupTaskState
    {
        $task = $this->tasks[$taskId] ?? null;

        if ($task === null) {
            // A task the provider has never heard of. Reported as a refusal
            // rather than as "still running", because a poller told to keep
            // waiting for a task that does not exist waits for ever.
            throw BackupProviderException::refused(self::NAME, 'backup_task_status', 'no such task', ['task_id' => $taskId]);
        }

        $this->tasks[$taskId]['polls']++;

        if ($this->tasks[$taskId]['polls'] <= $this->pollsBeforeSettling) {
            return new BackupTaskState($taskId, finished: false, successful: false);
        }

        if ($task['failing']) {
            return new BackupTaskState($taskId, finished: true, successful: false, exitStatus: 'job failed: no space left on device');
        }

        $request = $task['request'];
        $archiveId = sprintf('vzdump-qemu-%s-%d.vma.zst', $request->providerId, $this->nextId++);

        $this->stored[$request->datastore.'|'.$request->providerId][] = new RemoteBackup(
            archiveId: $archiveId,
            datastore: $request->datastore,
            sizeBytes: 1_073_741_824,
            createdAt: 1_700_000_000,
            verified: null,
            notes: $request->notes,
        );

        return new BackupTaskState($taskId, finished: true, successful: true, exitStatus: 'OK', sizeBytes: 1_073_741_824, archiveId: $archiveId);
    }

    public function startVerification(string $nodeName, string $datastore, string $archiveId): BackupOperation
    {
        $taskId = 'UPID:fake-verify:'.$this->nextId++;

        $this->tasks[$taskId] = ['failing' => false, 'polls' => 0, 'request' => new BackupRequest($nodeName, '0', $datastore)];

        return new BackupOperation($taskId, $nodeName);
    }

    public function startRestore(string $nodeName, string $providerId, string $datastore, string $archiveId): BackupOperation
    {
        $taskId = 'UPID:fake-restore:'.$this->nextId++;

        $this->tasks[$taskId] = ['failing' => false, 'polls' => 0, 'request' => new BackupRequest($nodeName, $providerId, $datastore)];

        return new BackupOperation($taskId, $nodeName);
    }

    public function listBackups(string $nodeName, string $datastore, string $providerId): array
    {
        return $this->stored[$datastore.'|'.$providerId] ?? [];
    }

    public function deleteBackup(string $nodeName, string $datastore, string $archiveId): void
    {
        foreach ($this->stored as $key => $backups) {
            $this->stored[$key] = array_values(array_filter(
                $backups,
                static fn (RemoteBackup $backup): bool => $backup->archiveId !== $archiveId,
            ));
        }
    }

    /**
     * @throws BackupProviderException
     */
    private function refuseMarked(string $subject, string $operation): void
    {
        if (str_contains($subject, self::TIMEOUT_MARKER)) {
            throw BackupProviderException::timedOut(self::NAME, $operation);
        }

        if (str_contains($subject, self::REFUSAL_MARKER)) {
            throw BackupProviderException::refused(
                self::NAME,
                $operation,
                sprintf('POST /nodes/pve/vzdump 401 (Authorization: PVEAPIToken=%s)', self::CLUSTER_TOKEN),
            );
        }
    }
}
