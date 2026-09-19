<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\DTOs;

/**
 * One file out of an archive, as a stream the response drains.
 *
 * @property resource $stream
 */
final readonly class BackupFileContent
{
    /**
     * @param  resource  $stream
     */
    public function __construct(
        public string $name,
        public int $sizeBytes,
        public mixed $stream,
    ) {}
}
