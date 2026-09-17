<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Infrastructure\Providers;

use Lynomia\Modules\Backups\Domain\Contracts\BackupProvider;
use Lynomia\Modules\Backups\Domain\Contracts\FileLevelBackupProvider;
use Lynomia\Modules\Backups\Domain\DTOs\BackupFileContent;
use Lynomia\Modules\Backups\Domain\DTOs\BackupFileEntry;
use Lynomia\Modules\Backups\Domain\DTOs\BackupFileListing;
use Lynomia\Modules\Backups\Domain\DTOs\BackupOperation;
use Lynomia\Modules\Backups\Domain\DTOs\BackupRequest;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\DTOs\RemoteBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupFileKind;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupFileRefusedException;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
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
final class FakeBackupProvider implements BackupProvider, FileLevelBackupProvider
{
    public const string NAME = 'fake';

    /**
     * What every archive holds, for the file-level paths.
     *
     * One tree for every archive, because the platform must handle every
     * kind of entry and the tests must reach every kind: a nested
     * directory, regular files of known sizes, a symlink (never followed),
     * a device node (never anything), a file too large to download, and the
     * three markers that make a restore time out, be refused, or fail
     * after starting.
     *
     * @var array<string, array{kind: BackupFileKind, size: ?int}>
     */
    private const array TREE = [
        '/etc' => ['kind' => BackupFileKind::Directory, 'size' => null],
        '/etc/hostname' => ['kind' => BackupFileKind::File, 'size' => 12],
        '/etc/localtime' => ['kind' => BackupFileKind::Symlink, 'size' => null],
        '/etc/nginx' => ['kind' => BackupFileKind::Directory, 'size' => null],
        '/etc/nginx/nginx.conf' => ['kind' => BackupFileKind::File, 'size' => 1_432],
        '/etc/nginx/sites-enabled' => ['kind' => BackupFileKind::Directory, 'size' => null],
        '/etc/nginx/sites-enabled/default' => ['kind' => BackupFileKind::Symlink, 'size' => null],
        '/var' => ['kind' => BackupFileKind::Directory, 'size' => null],
        '/var/www' => ['kind' => BackupFileKind::Directory, 'size' => null],
        '/var/www/index.html' => ['kind' => BackupFileKind::File, 'size' => 612],
        '/var/www/archive.tar' => ['kind' => BackupFileKind::File, 'size' => 4 * 1024 * 1024 * 1024],
        '/dev' => ['kind' => BackupFileKind::Directory, 'size' => null],
        '/dev/null' => ['kind' => BackupFileKind::Other, 'size' => null],
        '/marker' => ['kind' => BackupFileKind::Directory, 'size' => null],
        '/marker/'.self::TIMEOUT_MARKER => ['kind' => BackupFileKind::File, 'size' => 1],
        '/marker/'.self::REFUSAL_MARKER => ['kind' => BackupFileKind::File, 'size' => 1],
        '/marker/'.self::FAILING_MARKER => ['kind' => BackupFileKind::File, 'size' => 1],
    ];

    public const string REFUSAL_MARKER = 'backup-refused';

    public const string TIMEOUT_MARKER = 'backup-timeout';

    /** A task that is accepted, runs, and then fails. */
    public const string FAILING_MARKER = 'backup-fails';

    /** A credential-shaped string the refusal quotes back, so redaction is testable. */
    private const string CLUSTER_TOKEN = 'fake-pve-token-0123456789abcdef';

    /**
     * What a task is doing.
     *
     * Carried on the task rather than inferred from its id prefix, because
     * `taskState()` has to answer differently for each: a backup produces an
     * archive, a verification writes a verdict onto one, and a restore
     * produces neither.
     */
    private const string KIND_BACKUP = 'backup';

    private const string KIND_VERIFY = 'verify';

    private const string KIND_RESTORE = 'restore';

    private const string KIND_FILE_RESTORE = 'file_restore';

    /** @var array<string, array{failing: bool, polls: int, request: BackupRequest, kind: string, archive: ?string}> */
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
            'kind' => self::KIND_BACKUP,
            'archive' => null,
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
            /*
             * A verification that ran and failed is recorded on the archive,
             * because that is what the real datastore does: the verdict of the
             * last verification is visible in a storage listing, and three
             * answers are needed rather than two — never verified is not the
             * same as verified and failed.
             */
            if ($task['kind'] === self::KIND_VERIFY) {
                $this->recordVerification($task, verified: false);
            }

            return new BackupTaskState($taskId, finished: true, successful: false, exitStatus: 'job failed: no space left on device');
        }

        if ($task['kind'] === self::KIND_VERIFY) {
            $this->recordVerification($task, verified: true);

            return new BackupTaskState($taskId, finished: true, successful: true, exitStatus: 'OK', archiveId: $task['archive']);
        }

        if ($task['kind'] !== self::KIND_BACKUP) {
            // A restore produces no new archive; reporting one would invent a
            // second copy of the thing being put back.
            return new BackupTaskState($taskId, finished: true, successful: true, exitStatus: 'OK', archiveId: $task['archive']);
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

    /**
     * Ask the datastore to prove an archive can be read back.
     *
     * The markers are read from the archive id, which is the only thing this
     * call carries that a test can choose. It matters that verification can
     * fail at all: "the backup completed" and "the backup can be restored" are
     * different questions — a deduplicating datastore shares chunks, so one
     * corrupt chunk can be shared by many archives — and a simulator whose
     * verification always succeeded would leave the whole verification-failed
     * path, and the alerts that read it, unexercised.
     */
    public function startVerification(string $nodeName, string $datastore, string $archiveId): BackupOperation
    {
        $this->refuseMarked($archiveId, 'start_verification');

        $taskId = 'UPID:fake-verify:'.$this->nextId++;

        $this->tasks[$taskId] = [
            'failing' => str_contains($archiveId, self::FAILING_MARKER),
            'polls' => 0,
            'request' => new BackupRequest($nodeName, '0', $datastore),
            'kind' => self::KIND_VERIFY,
            'archive' => $archiveId,
        ];

        return new BackupOperation($taskId, $nodeName);
    }

    /**
     * Put an archive back, and be able to fail at it.
     *
     * A restore that cannot fail is the one simulator behaviour that would
     * make a backup product look finished when it is not: everything the
     * platform does about a failed restore — the operator alert, the refusal
     * to mark a machine recovered, the ticket — would be unreachable.
     */
    public function startRestore(string $nodeName, string $providerId, string $datastore, string $archiveId): BackupOperation
    {
        $this->refuseMarked($archiveId, 'start_restore');

        $taskId = 'UPID:fake-restore:'.$this->nextId++;

        $this->tasks[$taskId] = [
            'failing' => str_contains($archiveId, self::FAILING_MARKER),
            'polls' => 0,
            'request' => new BackupRequest($nodeName, $providerId, $datastore),
            'kind' => self::KIND_RESTORE,
            'archive' => $archiveId,
        ];

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

    public function listFiles(string $nodeName, string $datastore, string $archiveId, BackupPath $path): BackupFileListing
    {
        if (! $path->isRoot()) {
            $entry = self::TREE[$path->value] ?? null;

            if ($entry === null) {
                throw BackupFileRefusedException::notFound($path->value);
            }

            if ($entry['kind'] === BackupFileKind::Symlink) {
                throw BackupFileRefusedException::symlink($path->value);
            }

            if ($entry['kind'] !== BackupFileKind::Directory) {
                throw BackupFileRefusedException::notADirectory($path->value);
            }
        }

        $entries = [];

        foreach (self::TREE as $candidate => $entry) {
            $child = BackupPath::of($candidate);

            if ($child->parent()->equals($path)) {
                $entries[] = new BackupFileEntry($child, $entry['kind'], $entry['size'], 1_700_000_000);
            }
        }

        return new BackupFileListing($path, $entries);
    }

    public function readFile(string $nodeName, string $datastore, string $archiveId, BackupPath $path, int $maxBytes): BackupFileContent
    {
        $entry = self::TREE[$path->value] ?? null;

        if ($entry === null) {
            throw BackupFileRefusedException::notFound($path->value);
        }

        if ($entry['kind'] === BackupFileKind::Symlink) {
            throw BackupFileRefusedException::symlink($path->value);
        }

        if ($entry['kind'] !== BackupFileKind::File) {
            throw BackupFileRefusedException::notAFile($path->value);
        }

        if ((int) $entry['size'] > $maxBytes) {
            throw BackupFileRefusedException::tooLarge($path->value, $maxBytes);
        }

        // Deterministic and archive-specific, so a test can prove the bytes
        // came from the archive that was asked for and not another.
        $body = sprintf("# %s from %s\n", $path->value, $archiveId);
        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            throw BackupProviderException::refused(self::NAME, 'file_read', 'no memory stream');
        }

        fwrite($stream, $body);
        rewind($stream);

        return new BackupFileContent($path->name(), strlen($body), $stream);
    }

    public function startFileRestore(string $nodeName, string $providerId, string $datastore, string $archiveId, array $paths): BackupOperation
    {
        $joined = implode(' ', array_map(static fn (BackupPath $p): string => $p->value, $paths));

        $this->refuseMarked($joined, 'file_restore');

        $taskId = 'UPID:fake-file-restore:'.$this->nextId++;

        $this->tasks[$taskId] = [
            'failing' => str_contains($joined, self::FAILING_MARKER),
            'polls' => 0,
            'request' => new BackupRequest($nodeName, $providerId, $datastore),
            'kind' => self::KIND_FILE_RESTORE,
            'archive' => $archiveId,
        ];

        return new BackupOperation($taskId, $nodeName);
    }

    /**
     * Write a verification verdict onto the archive it was about.
     *
     * @param  array{failing: bool, polls: int, request: BackupRequest, kind: string, archive: ?string}  $task
     */
    private function recordVerification(array $task, bool $verified): void
    {
        if ($task['archive'] === null) {
            return;
        }

        foreach ($this->stored as $key => $backups) {
            foreach ($backups as $index => $backup) {
                if ($backup->archiveId !== $task['archive']) {
                    continue;
                }

                $this->stored[$key][$index] = new RemoteBackup(
                    archiveId: $backup->archiveId,
                    datastore: $backup->datastore,
                    sizeBytes: $backup->sizeBytes,
                    createdAt: $backup->createdAt,
                    verified: $verified,
                    notes: $backup->notes,
                );
            }
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
