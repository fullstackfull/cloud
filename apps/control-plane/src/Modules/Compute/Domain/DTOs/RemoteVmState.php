<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\DTOs;

use Lynomia\Modules\Compute\Domain\Enums\PowerState;

/**
 * The hypervisor's own view of one machine.
 *
 * This is what reconciliation compares the local row against, so it carries
 * the shape as well as the power state: a machine an operator resized by hand
 * is drift the platform has to notice, because the customer is still being
 * billed for what they bought.
 *
 * @immutable
 */
final readonly class RemoteVmState
{
    /**
     * @param  array<string, mixed>  $raw  Redacted provider payload, kept for operator diagnosis.
     */
    public function __construct(
        public string $providerId,
        public string $nodeName,
        public ?string $name,
        public PowerState $powerState,
        public ?int $vcpu = null,
        public ?int $memoryMib = null,
        public ?int $diskGib = null,
        public ?int $uptimeSeconds = null,
        public array $raw = [],
    ) {}
}
