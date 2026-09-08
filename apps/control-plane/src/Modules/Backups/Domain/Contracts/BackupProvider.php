<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Contracts;

use Lynomia\Modules\Backups\Domain\DTOs\BackupOperation;
use Lynomia\Modules\Backups\Domain\DTOs\BackupRequest;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\DTOs\RemoteBackup;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;

/**
 * Whatever actually takes and keeps backups of a customer's machine.
 *
 * ---------------------------------------------------------------------------
 * What this is not
 * ---------------------------------------------------------------------------
 *
 * It is not the platform's own backups. Lynomia's database, its object storage
 * and its configuration are backed up by infrastructure automation — the PBS
 * Ansible role, which schedules verification and a restore test — and nothing
 * about them appears here. The two are separated because they fail differently
 * and are owned by different people: a customer's VM backup failing is a
 * support ticket, and the platform's own database backup failing is an
 * emergency that no customer should be able to see, let alone trigger.
 *
 * ---------------------------------------------------------------------------
 * Why every mutation returns a task
 * ---------------------------------------------------------------------------
 *
 * A backup takes minutes to hours. No method here waits for one: each returns
 * the provider's handle on an operation that is now under way, and the platform
 * polls. That shape is deliberate — an interface with a blocking `backup()`
 * that returns a boolean forces every caller to either hold a request open for
 * an hour or to lie about the result, and the second is what actually happens.
 *
 * Nothing in an implementation may retry. A create that times out may have
 * started a backup; asking again runs a second one over the same disks.
 */
interface BackupProvider
{
    public function name(): string;

    /**
     * Begin a backup. Returns as soon as the provider has accepted it.
     *
     * @throws BackupProviderException
     */
    public function startBackup(BackupRequest $request): BackupOperation;

    /**
     * What the provider says about a task it was given.
     *
     * @throws BackupProviderException
     */
    public function taskState(string $nodeName, string $taskId): BackupTaskState;

    /**
     * Whether this provider can be asked to start a verification at all.
     *
     * Asked rather than attempted, because the answer is a property of the
     * provider rather than of the request. Proxmox is the case that makes this
     * necessary: verification of a PBS-backed archive is a datastore-side job
     * scheduled on the backup server, not something the hypervisor API can
     * start — but the verdict of the last one is visible in a storage listing.
     * So the platform can report whether a backup verified without being able
     * to ask for a verification, and pretending otherwise would mean a button
     * that does nothing.
     */
    public function supportsVerification(): bool;

    /**
     * Begin verifying that a stored backup is actually readable.
     *
     * A deduplicating datastore shares chunks between backups, so one corrupt
     * chunk can be shared by many — which is why "the backup completed" and
     * "the backup can be restored" are two different questions, and why this
     * is a separate call rather than something `startBackup` could promise.
     *
     * @throws BackupProviderException
     */
    public function startVerification(string $nodeName, string $datastore, string $archiveId): BackupOperation;

    /**
     * Begin restoring a stored backup over a machine.
     *
     * Destructive by definition: the target's disks are replaced. Whoever calls
     * this is responsible for having established that the customer meant it.
     *
     * @throws BackupProviderException
     */
    public function startRestore(string $nodeName, string $providerId, string $datastore, string $archiveId): BackupOperation;

    /**
     * What the provider currently holds for one machine.
     *
     * @return list<RemoteBackup>
     *
     * @throws BackupProviderException
     */
    public function listBackups(string $nodeName, string $datastore, string $providerId): array;

    /**
     * Remove one stored backup.
     *
     * Deleting one that is already gone is not an error: the caller asked for
     * it to be absent, and it is.
     *
     * @throws BackupProviderException
     */
    public function deleteBackup(string $nodeName, string $datastore, string $archiveId): void;
}
