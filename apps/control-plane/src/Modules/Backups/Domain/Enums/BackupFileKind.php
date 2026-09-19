<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Enums;

/**
 * What an archive entry is. Only the first two are anything the platform
 * will open: a symlink is shown so a customer can see it is there, and is
 * never followed, read or restored.
 */
enum BackupFileKind: string
{
    case File = 'file';
    case Directory = 'directory';
    case Symlink = 'symlink';
    case Other = 'other';

    public function isDownloadable(): bool
    {
        return $this === self::File;
    }

    public function isBrowsable(): bool
    {
        return $this === self::Directory;
    }

    public function isRestorable(): bool
    {
        return $this === self::File || $this === self::Directory;
    }
}
