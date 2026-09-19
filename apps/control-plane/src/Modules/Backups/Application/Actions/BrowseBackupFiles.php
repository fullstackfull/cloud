<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\DTOs\BackupFileListing;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupFileRefusedException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;

/**
 * One directory of one backup, read straight from the provider.
 *
 * Not cached and not stored: an archive does not change, but what the
 * platform must never do is show a listing from one archive under the name
 * of another, and a cache keyed on anything less than the archive would be
 * the way that happens. The listing is bounded by the provider to what a
 * screen can show; a customer looking for a needle narrows the path.
 */
final readonly class BrowseBackupFiles
{
    public function __construct(
        private FileLevelSupport $support,
    ) {}

    /**
     * @throws BackupFileRefusedException
     */
    public function execute(Backup $backup, BackupPath $path): BackupFileListing
    {
        $provider = $this->support->provider($backup);

        return $provider->listFiles($backup->node_name, $backup->datastore, (string) $backup->archive_id, $path);
    }
}
