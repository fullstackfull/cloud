<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\DTOs;

use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;

/**
 * One directory of an archive. `truncated` says the provider had more
 * than the platform would show; a customer looking for a file in a
 * directory of a hundred thousand narrows the path rather than pages.
 */
final readonly class BackupFileListing
{
    public const int MAX_ENTRIES = 1_000;

    /**
     * @param  list<BackupFileEntry>  $entries
     */
    public function __construct(
        public BackupPath $path,
        public array $entries,
        public bool $truncated = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path->value,
            'parent' => $this->path->isRoot() ? null : $this->path->parent()->value,
            'truncated' => $this->truncated,
            'entries' => array_map(static fn (BackupFileEntry $e): array => $e->toArray(), $this->entries),
        ];
    }
}
