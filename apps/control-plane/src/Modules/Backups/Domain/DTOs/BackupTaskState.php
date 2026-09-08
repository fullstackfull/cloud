<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\DTOs;

/**
 * What a provider says about a task it was given.
 *
 * `running` and `finished` are carried apart from `successful` on purpose. A
 * provider that reports a task as stopped without yet reporting an exit status
 * has not finished it, and treating the pair as one field is how a backup gets
 * marked complete before its last chunk is written.
 */
final readonly class BackupTaskState
{
    public function __construct(
        public string $taskId,
        public bool $finished,
        public bool $successful,
        /** The provider's own words, already scrubbed of anything credential-shaped. */
        public ?string $exitStatus = null,
        /** Bytes written, when the provider reports it. */
        public ?int $sizeBytes = null,
        /** The provider's identifier for the resulting archive, when it has one. */
        public ?string $archiveId = null,
    ) {}

    public function isRunning(): bool
    {
        return ! $this->finished;
    }

    public function hasFailed(): bool
    {
        return $this->finished && ! $this->successful;
    }
}
