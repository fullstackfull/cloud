<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\Enums\BackupFileKind;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupFileRefusedException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileDownload;

/**
 * A short-lived, single-use link to one file in one backup.
 *
 * The file is checked to exist and to be a regular file before the link is
 * minted, by listing its directory: a link to a symlink or a directory is
 * refused here with the reason, rather than at the moment the browser
 * follows it and can only show an error page. The token is random, is
 * handed back exactly once, and is stored only as its hash.
 */
final readonly class IssueBackupFileDownload
{
    public function __construct(
        private FileLevelSupport $support,
    ) {}

    /**
     * @return array{download: BackupFileDownload, token: string}
     *
     * @throws BackupFileRefusedException
     */
    public function execute(Backup $backup, BackupPath $path, ?string $userId): array
    {
        if ($path->isRoot()) {
            throw BackupFileRefusedException::notAFile($path->value);
        }

        $provider = $this->support->provider($backup);

        $listing = $provider->listFiles($backup->node_name, $backup->datastore, (string) $backup->archive_id, $path->parent());

        $found = null;

        foreach ($listing->entries as $entry) {
            if ($entry->path->equals($path)) {
                $found = $entry;

                break;
            }
        }

        if ($found === null) {
            throw BackupFileRefusedException::notFound($path->value);
        }

        if ($found->kind === BackupFileKind::Symlink) {
            throw BackupFileRefusedException::symlink($path->value);
        }

        if (! $found->kind->isDownloadable()) {
            throw BackupFileRefusedException::notAFile($path->value);
        }

        $max = (int) config('backups.file_download_max_bytes', 64 * 1024 * 1024);

        if ($found->sizeBytes !== null && $found->sizeBytes > $max) {
            throw BackupFileRefusedException::tooLarge($path->value, $max);
        }

        $token = bin2hex(random_bytes(32));

        $download = BackupFileDownload::query()->create([
            'backup_id' => $backup->getKey(),
            'customer_id' => $backup->customer_id,
            'path' => $path->value,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addSeconds(max(30, (int) config('backups.file_download_ttl_seconds', 300))),
            'issued_by_user_id' => $userId,
        ]);

        return ['download' => $download, 'token' => $token];
    }
}
