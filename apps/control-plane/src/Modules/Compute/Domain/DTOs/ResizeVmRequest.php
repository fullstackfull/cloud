<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

/**
 * A change to a running machine's shape.
 *
 * Every field is optional because a resize is a patch: sending the fields that
 * did not change would overwrite whatever an operator adjusted by hand, and
 * the hypervisor cannot tell the difference between "unchanged" and "reset to
 * what the platform last believed".
 *
 * Disk shrink is not expressible on purpose. Hypervisors accept the request
 * and the guest filesystem does not survive it.
 *
 * @immutable
 */
final readonly class ResizeVmRequest
{
    public function __construct(
        public ?int $vcpu = null,
        public ?int $memoryMib = null,
        public ?int $diskGib = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->vcpu === null && $this->memoryMib === null && $this->diskGib === null;
    }
}
