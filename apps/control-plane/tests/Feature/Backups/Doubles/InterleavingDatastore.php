<?php

declare(strict_types=1);

namespace Tests\Feature\Backups\Doubles;

use Closure;
use Lynomia\Modules\Backups\Domain\Contracts\BackupProvider;
use Lynomia\Modules\Backups\Domain\DTOs\BackupOperation;
use Lynomia\Modules\Backups\Domain\DTOs\BackupRequest;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\DTOs\RemoteBackup;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;

/**
 * A datastore that lets a test run somebody else's request while a provider
 * call is out.
 *
 * Every race in this module sits in the same window: a sweep or a request has
 * read a row and is waiting on the provider, and meanwhile somebody moves the
 * row. `$whileVerifying` and `$whileTaskIsAsked` run inside those calls, which
 * is exactly where the other process would land. Tasks never finish unless a
 * test settles them, and verification can be made to refuse.
 */
final class InterleavingDatastore implements BackupProvider
{
    /** Run inside startVerification(), before it answers. */
    public ?Closure $whileVerifying = null;

    /** Run inside taskState(), before it answers. */
    public ?Closure $whileTaskIsAsked = null;

    public bool $refuseVerification = false;

    /** @var array<string, BackupTaskState> */
    public array $settled = [];

    public int $verificationsStarted = 0;

    private int $next = 1;

    public function name(): string
    {
        return 'fake';
    }

    public function startBackup(BackupRequest $request): BackupOperation
    {
        return new BackupOperation('UPID:interleave:backup-'.$this->next++, $request->nodeName);
    }

    public function taskState(string $nodeName, string $taskId): BackupTaskState
    {
        $this->run($this->whileTaskIsAsked);

        return $this->settled[$taskId] ?? new BackupTaskState($taskId, finished: false, successful: false);
    }

    public function supportsVerification(): bool
    {
        return true;
    }

    public function startVerification(string $nodeName, string $datastore, string $archiveId): BackupOperation
    {
        $this->verificationsStarted++;
        $this->run($this->whileVerifying);

        if ($this->refuseVerification) {
            throw BackupProviderException::refused('fake', 'backup_verify', 'datastore busy', []);
        }

        return new BackupOperation('UPID:interleave:verify-'.$this->next++, $nodeName);
    }

    public function startRestore(string $nodeName, string $providerId, string $datastore, string $archiveId): BackupOperation
    {
        return new BackupOperation('UPID:interleave:restore-'.$this->next++, $nodeName);
    }

    /**
     * @return list<RemoteBackup>
     */
    public function listBackups(string $nodeName, string $datastore, string $providerId): array
    {
        return [];
    }

    public function deleteBackup(string $nodeName, string $datastore, string $archiveId): void {}

    private function run(?Closure $hook): void
    {
        if ($hook === null) {
            return;
        }

        // Once: the other request lands in this window, not in every one.
        $this->whileVerifying = $hook === $this->whileVerifying ? null : $this->whileVerifying;
        $this->whileTaskIsAsked = $hook === $this->whileTaskIsAsked ? null : $this->whileTaskIsAsked;

        $hook();
    }
}
