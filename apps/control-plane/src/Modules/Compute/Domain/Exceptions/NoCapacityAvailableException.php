<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * Nothing in the fleet can host this machine.
 *
 * Thrown rather than returned as null so that a caller cannot accidentally
 * carry on with no node: an order that reaches provisioning with a null
 * placement is an order that gets billed and never built.
 *
 * The context carries the tally of why each candidate was rejected, because
 * "no capacity" is the one scheduler outcome an operator has to act on and the
 * fleet will have changed by the time anyone looks.
 */
final class NoCapacityAvailableException extends DomainException
{
    /**
     * @param  array<string, int>  $rejectionsByReason  Candidate count keyed by PlacementRejectionReason value.
     */
    public static function inCluster(
        string $clusterId,
        int $vcpu,
        int $memoryMib,
        int $diskGib,
        array $rejectionsByReason = [],
    ): self {
        $exception = new self(sprintf(
            'No active node can host a machine of %d vCPU, %d MiB and %d GiB in cluster %s.',
            $vcpu,
            $memoryMib,
            $diskGib,
            $clusterId,
        ));

        return $exception->withContext([
            'cluster_id' => $clusterId,
            'requested_vcpu' => $vcpu,
            'requested_memory_mib' => $memoryMib,
            'requested_disk_gib' => $diskGib,
            // Flattened to a string because exception context is scalar-only,
            // and this has to survive into a log line intact.
            'rejections' => self::summarise($rejectionsByReason),
        ]);
    }

    public function errorCode(): string
    {
        return 'compute.no_capacity_available';
    }

    /**
     * Capacity exhaustion is a temporary condition of the platform, not a
     * malformed request, so it is reported as a service-side failure that a
     * retry may resolve.
     */
    public function httpStatus(): int
    {
        return 503;
    }

    /**
     * @param  array<string, int>  $rejectionsByReason
     */
    private static function summarise(array $rejectionsByReason): string
    {
        if ($rejectionsByReason === []) {
            return 'no candidate nodes existed';
        }

        ksort($rejectionsByReason);

        return implode(', ', array_map(
            static fn (string $reason, int $count): string => $reason.'='.$count,
            array_keys($rejectionsByReason),
            array_values($rejectionsByReason),
        ));
    }
}
