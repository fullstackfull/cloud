<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Lynomia\Modules\Backups\Domain\Contracts\BackupProvider;
use Lynomia\Modules\Backups\Domain\DTOs\BackupOperation;
use Lynomia\Modules\Backups\Domain\DTOs\BackupRequest;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use ReflectionProperty;

/**
 * The shipped backup simulator, with a gap in the middle of its restore call.
 *
 * A restore is two writes around one provider call: the row moves to
 * `restoring`, the provider is asked, and only then is the handle it returned
 * written. Whatever else runs in that gap — a sweep in another process, or a
 * process dying — is what these tests are about, and the simulator on its own
 * answers too quickly for anything to land there.
 *
 * `$duringStartRestore` runs inside the call, before the simulator is asked.
 * It may throw, which is how a process that died mid-call is represented:
 * nothing after the call runs.
 */
final class ARestoreCallWithAWindow implements BackupProvider
{
    /** @var (Closure(): void)|null */
    public ?Closure $duringStartRestore = null;

    public function __construct(private readonly BackupProvider $inner) {}

    /**
     * Put this in front of whatever the factory built for the cluster.
     *
     * The factory memoises one adapter per cluster in a private map; replacing
     * the entry is the only seam that leaves the rest of the factory — and the
     * code under test — exactly as shipped.
     */
    public static function installFor(BackupProviderFactory $factory, ComputeCluster $cluster): self
    {
        $window = new self($factory->for($cluster));

        $resolved = new ReflectionProperty(BackupProviderFactory::class, 'resolved');
        /** @var array<string, BackupProvider> $map */
        $map = $resolved->getValue($factory);
        $map[(string) $cluster->getKey()] = $window;
        $resolved->setValue($factory, $map);

        return $window;
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function startBackup(BackupRequest $request): BackupOperation
    {
        return $this->inner->startBackup($request);
    }

    public function taskState(string $nodeName, string $taskId): BackupTaskState
    {
        return $this->inner->taskState($nodeName, $taskId);
    }

    public function supportsVerification(): bool
    {
        return $this->inner->supportsVerification();
    }

    public function startVerification(string $nodeName, string $datastore, string $archiveId): BackupOperation
    {
        return $this->inner->startVerification($nodeName, $datastore, $archiveId);
    }

    public function startRestore(string $nodeName, string $providerId, string $datastore, string $archiveId): BackupOperation
    {
        if ($this->duringStartRestore !== null) {
            ($this->duringStartRestore)();
        }

        return $this->inner->startRestore($nodeName, $providerId, $datastore, $archiveId);
    }

    public function listBackups(string $nodeName, string $datastore, string $providerId): array
    {
        return $this->inner->listBackups($nodeName, $datastore, $providerId);
    }

    public function deleteBackup(string $nodeName, string $datastore, string $archiveId): void
    {
        $this->inner->deleteBackup($nodeName, $datastore, $archiveId);
    }
}
