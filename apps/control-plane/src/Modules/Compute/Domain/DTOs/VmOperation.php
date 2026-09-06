<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;

/**
 * A mutation the hypervisor has accepted but not necessarily finished.
 *
 * The task id is mandatory, and that is the whole point of this type. Creating
 * a machine takes minutes; the API answers in milliseconds with a job handle
 * and nothing else. The provisioning engine persists that handle *before* it
 * treats the call as in flight, so that a worker which dies mid-build can ask
 * the hypervisor what happened instead of assuming failure and building a
 * second machine the platform will never bill for and never delete.
 *
 * A provider whose operation is genuinely synchronous still returns one — it
 * reports its own completed status with a synthetic id — so callers never have
 * to branch on whether a task exists.
 *
 * @immutable
 */
final readonly class VmOperation
{
    /**
     * @param  string  $taskId  The provider's job handle. On Proxmox this is the UPID.
     * @param  array<string, mixed>  $metadata  Redacted provider detail, safe to persist.
     */
    public function __construct(
        public string $taskId,
        public string $nodeName,
        public string $providerId,
        public string $operation,
        public RemoteTaskStatus $status = RemoteTaskStatus::Running,
        public array $metadata = [],
    ) {}

    /** Whether the caller still has to poll getTask() for the outcome. */
    public function isInFlight(): bool
    {
        return ! $this->status->isFinished();
    }
}
