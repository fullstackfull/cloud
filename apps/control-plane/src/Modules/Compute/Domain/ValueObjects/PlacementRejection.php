<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\ValueObjects;

use Lynomia\Modules\Compute\Domain\Enums\PlacementRejectionReason;

/**
 * A node that was not eligible, and why.
 *
 * Rejections are collected rather than discarded so that "no capacity" can be
 * answered without re-running the scheduler against a fleet that has moved on.
 *
 * @immutable
 */
final readonly class PlacementRejection
{
    public function __construct(
        public string $nodeId,
        public string $providerName,
        public PlacementRejectionReason $reason,
        public string $detail,
    ) {}

    /**
     * @return array{node_id: string, node: string, reason: string, detail: string}
     */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'node' => $this->providerName,
            'reason' => $this->reason->value,
            'detail' => $this->detail,
        ];
    }
}
