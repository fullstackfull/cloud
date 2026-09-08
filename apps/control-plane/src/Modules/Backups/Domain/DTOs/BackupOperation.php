<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\DTOs;

/**
 * A provider has begun something, and this is the handle on it.
 *
 * Every mutating call returns one. The identifier is the whole value: without
 * it the platform has started an operation it cannot ask about, and the only
 * remaining way to find out what happened is a person opening the provider's
 * own interface.
 */
final readonly class BackupOperation
{
    public function __construct(
        public string $taskId,
        public string $nodeName,
    ) {}
}
