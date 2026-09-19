<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Lynomia\Modules\Backups\Domain\DTOs\BackupFileContent;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupFileRefusedException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileDownload;

/**
 * Follow a download link: spend it, then read the file it names.
 *
 * The link is spent first, in one conditional update, so two requests
 * carrying the same token cannot both be served. The token is looked up by
 * hash and compared in constant time through the index; the customer
 * scope is a second condition on the same row, so a token that leaks to
 * another account is "no such link" and not "somebody else's file".
 */
final readonly class ServeBackupFileDownload
{
    public function __construct(
        private FileLevelSupport $support,
    ) {}

    /**
     * @return array{download: BackupFileDownload, backup: Backup, content: BackupFileContent}
     *
     * @throws BackupFileRefusedException
     */
    public function execute(string $token, string $customerId): array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            throw BackupFileRefusedException::downloadNotAvailable();
        }

        $hash = hash('sha256', $token);

        $spent = BackupFileDownload::query()
            ->where('token_hash', $hash)
            ->where('customer_id', $customerId)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]);

        if ($spent !== 1) {
            throw BackupFileRefusedException::downloadNotAvailable();
        }

        /** @var BackupFileDownload $download */
        $download = BackupFileDownload::query()->where('token_hash', $hash)->firstOrFail();

        /** @var Backup $backup */
        $backup = $download->backup()->firstOrFail();

        $provider = $this->support->provider($backup);

        $content = $provider->readFile(
            $backup->node_name,
            $backup->datastore,
            (string) $backup->archive_id,
            BackupPath::of($download->path),
            (int) config('backups.file_download_max_bytes', 64 * 1024 * 1024),
        );

        return ['download' => $download, 'backup' => $backup, 'content' => $content];
    }
}
