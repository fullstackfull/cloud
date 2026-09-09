<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

final class BackupFileRefusedException extends DomainException
{
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $status,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public static function unsupported(string $provider): self
    {
        return (new self(
            'This backup\'s provider cannot open archives file by file. The whole machine can still be restored.',
            'backup.file_level_unsupported',
            409,
        ))->withContext(['provider' => $provider]);
    }

    public static function badPath(string $because): self
    {
        return new self(
            sprintf('That path cannot be used: %s.', $because),
            'backup.file_path_invalid',
            422,
        );
    }

    public static function notAvailable(string $backupId, string $because): self
    {
        return (new self(
            sprintf('The files in this backup cannot be opened: %s.', $because),
            'backup.files_unavailable',
            422,
        ))->withContext(['backup_id' => $backupId]);
    }

    public static function notADirectory(string $path): self
    {
        return (new self('That path is not a directory in this backup.', 'backup.not_a_directory', 422))
            ->withContext(['path' => $path]);
    }

    public static function notAFile(string $path): self
    {
        return (new self('That path is not a regular file in this backup.', 'backup.not_a_file', 422))
            ->withContext(['path' => $path]);
    }

    public static function symlink(string $path): self
    {
        return (new self(
            'That path is a symbolic link. Links are never followed out of a backup: they can point anywhere.',
            'backup.symlink_refused',
            422,
        ))->withContext(['path' => $path]);
    }

    public static function notFound(string $path): self
    {
        return (new self('There is nothing at that path in this backup.', 'backup.file_not_found', 404))
            ->withContext(['path' => $path]);
    }

    public static function tooLarge(string $path, int $maxBytes): self
    {
        return (new self(
            sprintf('That file is larger than the %d MiB a download may be. Restore it to the machine instead.', intdiv($maxBytes, 1_048_576)),
            'backup.file_too_large',
            422,
        ))->withContext(['path' => $path]);
    }

    public static function tooManyPaths(int $max): self
    {
        return new self(
            sprintf('A file restore names at most %d paths. Restore a directory to bring back everything under it.', $max),
            'backup.too_many_paths',
            422,
        );
    }

    public static function confirmationMismatch(): self
    {
        return new self(
            'Type the machine\'s hostname exactly to confirm. A file restore replaces those files on it.',
            'backup.file_restore_confirmation_mismatch',
            422,
        );
    }

    public static function alreadyRestoring(string $machineId): self
    {
        return (new self(
            'A restore into this machine is already running. Wait for it to finish before starting another.',
            'backup.file_restore_in_flight',
            409,
        ))->withContext(['virtual_machine_id' => $machineId]);
    }

    public static function downloadNotAvailable(): self
    {
        // One answer for "no such link", "expired" and "already used": a
        // link is not a thing whose history the API will confirm.
        return new self('That download link is no longer valid. Ask for a new one.', 'backup.download_unavailable', 404);
    }
}
