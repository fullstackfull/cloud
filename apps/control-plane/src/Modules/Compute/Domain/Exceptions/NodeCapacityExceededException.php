<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Exceptions;

use Lynomia\Modules\Compute\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A reservation was refused because the node no longer has room.
 *
 * This is the exception the lock exists to make possible. Two placements can
 * pass scoring against the same node concurrently — scoring reads without a
 * lock, by design, because holding one across the whole fleet would serialise
 * every order in the platform. The re-check under the row lock is where the
 * loser of that race finds out, and it has to be an exception rather than a
 * degraded success: the alternative is a node whose committed allocation
 * exceeds its physical memory, which ends as an OOM kill on somebody's
 * database.
 */
final class NodeCapacityExceededException extends DomainException
{
    public static function forNode(
        string $nodeId,
        string $providerName,
        PlacementRejectionReason $reason,
        string $detail,
    ): self {
        $exception = new self(sprintf(
            'Node %s could not accept the reservation: %s.',
            $providerName,
            $detail,
        ));

        return $exception->withContext([
            'node_id' => $nodeId,
            'node' => $providerName,
            'reason' => $reason->value,
        ]);
    }

    public function errorCode(): string
    {
        return 'compute.node_capacity_exceeded';
    }

    /**
     * The caller should re-place rather than retry against this node, and a
     * conflict is what says so.
     */
    public function httpStatus(): int
    {
        return 409;
    }
}
