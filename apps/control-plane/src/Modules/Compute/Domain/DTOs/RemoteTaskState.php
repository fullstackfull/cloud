<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;

/**
 * Where an asynchronous hypervisor job has got to.
 *
 * The exit status is kept verbatim (after redaction) rather than collapsed
 * into the boolean, because "OK" and "unable to parse directory volume name"
 * are both terminal and only one of them is worth waking an operator for.
 *
 * @immutable
 */
final readonly class RemoteTaskState
{
    public function __construct(
        public string $taskId,
        public string $nodeName,
        public RemoteTaskStatus $status,
        public ?string $exitStatus = null,
        public ?int $startedAt = null,
    ) {}

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }
}
