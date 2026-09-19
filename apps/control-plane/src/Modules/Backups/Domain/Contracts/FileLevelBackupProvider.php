<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Contracts;

use Lynomia\Modules\Backups\Domain\DTOs\BackupFileContent;
use Lynomia\Modules\Backups\Domain\DTOs\BackupFileListing;
use Lynomia\Modules\Backups\Domain\DTOs\BackupOperation;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupFileRefusedException;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
use Lynomia\Modules\SharedHosting\Domain\Contracts\WordPressInstaller;

/**
 * A backup provider that can open an archive file by file, for the ones
 * that can.
 *
 * ===========================================================================
 * WHY THIS IS A SEPARATE INTERFACE
 * ===========================================================================
 *
 * The same reason {@see WordPressInstaller}
 * is not more methods on the hosting provider. Opening a disk image and
 * reading one file out of it is not something every backup system does: it
 * needs a helper that mounts the image (Proxmox ships `proxmox-file-restore`
 * as a separate package, on the hypervisor, with its own API), and it is
 * absent from plenty of otherwise healthy backup stacks. A provider that can
 * do it implements this; one that cannot, does not, and every surface says
 * "not with this provider" instead of offering a button that throws.
 *
 * ===========================================================================
 * WHAT IS NOT HERE, AND WHY
 * ===========================================================================
 *
 * There is no Proxmox implementation in this build. PVE's file-restore API
 * (`/nodes/{node}/storage/{storage}/file-restore/list` and `/download`)
 * exists in the documentation for recent versions and has never been called
 * from this platform against a real backup server: which versions expose it,
 * what it answers for a symlink, how large a download it streams, and what
 * a timeout means — none of that has been observed. Writing the adapter from
 * the documentation and testing it against a fake shaped like the guess
 * would produce green tests and a first real restore made from fiction.
 * Until a PBS-backed hypervisor is reachable, the Proxmox provider answers
 * "not supported" and this interface has one implementation, the fake.
 *
 * ===========================================================================
 * THE RULES EVERY IMPLEMENTATION KEEPS
 * ===========================================================================
 *
 *  - Paths are the archive's own, rooted at `/`. Nothing about where the
 *    archive lives on a datastore, a mount point on a hypervisor, or a
 *    helper's temporary directory is ever returned or accepted.
 *  - A symlink is listed as a symlink and is never followed: not read, not
 *    descended into, not restored. Following one would read whatever the
 *    guest pointed it at — on the hypervisor, at restore time.
 *  - Reads are bounded by the caller; an implementation may not stream more
 *    than it was asked for.
 *  - Nothing retries. A restore that times out may be writing into the
 *    machine right now.
 */
interface FileLevelBackupProvider
{
    /**
     * What the archive holds at one directory.
     *
     * @throws BackupFileRefusedException when the path is not a directory
     * @throws BackupProviderException
     */
    public function listFiles(string $nodeName, string $datastore, string $archiveId, BackupPath $path): BackupFileListing;

    /**
     * One regular file, as a stream, up to the given size.
     *
     * @throws BackupFileRefusedException when the path is not a regular file, or larger than allowed
     * @throws BackupProviderException
     */
    public function readFile(string $nodeName, string $datastore, string $archiveId, BackupPath $path, int $maxBytes): BackupFileContent;

    /**
     * Begin restoring the named paths into the machine, at the same paths.
     *
     * Destructive: whatever the machine holds at those paths is replaced.
     * Whoever calls this is responsible for having established that the
     * customer meant it.
     *
     * @param  list<BackupPath>  $paths
     *
     * @throws BackupProviderException
     */
    public function startFileRestore(string $nodeName, string $providerId, string $datastore, string $archiveId, array $paths): BackupOperation;
}
