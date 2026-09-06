<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Lynomia\Modules\SharedHosting\Domain\Enums\PlacementRejectionReason;

/**
 * The node the scheduler chose could not take the account after all.
 *
 * Raised by the reservation under the node's row lock, which is the only place
 * that can know. Scoring reads without a lock on purpose — holding one across
 * the fleet would serialise every order the platform takes — so two workers
 * can both choose the same node in the same millisecond and both be right when
 * they choose. The second one re-reads the row it is about to write and
 * discovers the world moved.
 */
final class NodeAtCapacityException extends DomainException
{
    public static function forNode(
        string $nodeId,
        string $hostname,
        PlacementRejectionReason $reason,
        string $detail,
    ): self {
        $exception = new self(sprintf(
            'Hosting node %s can no longer take this account: %s.',
            $hostname,
            $detail,
        ));

        return $exception->withContext([
            'node_id' => $nodeId,
            'hostname' => $hostname,
            'reason' => $reason->value,
            'detail' => $detail,
            // Whether the customer should be sent elsewhere or told to wait.
            'resolves_with_time' => $reason->resolvesWithTime(),
        ]);
    }

    public function errorCode(): string
    {
        return 'hosting.node_at_capacity';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
