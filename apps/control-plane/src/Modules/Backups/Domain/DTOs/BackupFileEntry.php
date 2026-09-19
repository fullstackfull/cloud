<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\DTOs;

use Lynomia\Modules\Backups\Domain\Enums\BackupFileKind;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;

final readonly class BackupFileEntry
{
    public function __construct(
        public BackupPath $path,
        public BackupFileKind $kind,
        public ?int $sizeBytes = null,
        public ?int $modifiedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path->value,
            'name' => $this->path->name(),
            'kind' => $this->kind->value,
            'size_bytes' => $this->sizeBytes,
            'modified_at' => $this->modifiedAt === null ? null : date(DATE_ATOM, $this->modifiedAt),
            'downloadable' => $this->kind->isDownloadable(),
            'browsable' => $this->kind->isBrowsable(),
            'restorable' => $this->kind->isRestorable(),
        ];
    }
}
