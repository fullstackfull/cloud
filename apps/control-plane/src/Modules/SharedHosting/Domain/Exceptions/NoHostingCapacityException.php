<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * No node in the fleet may take this account.
 *
 * Thrown rather than returned as null so that a caller cannot accidentally
 * carry on with no node: an order that reaches provisioning with a null
 * placement is an order that gets billed and never built.
 *
 * The context carries the tally of why each candidate was rejected. On a
 * shared platform the distinction inside that tally is what decides who has to
 * act: nodes rejected for disk pass on their own as accounts churn, whereas a
 * fleet rejected for licensing needs somebody to buy a licence, and no amount
 * of retrying will produce one.
 */
final class NoHostingCapacityException extends DomainException
{
    /**
     * @param  array<string, int>  $rejectionsByReason  Candidate count keyed by PlacementRejectionReason value.
     */
    public static function forPackage(
        string $packageSlug,
        ?string $regionId,
        array $rejectionsByReason = [],
    ): self {
        $exception = new self(sprintf(
            'No hosting node can take an account on package %s%s.',
            $packageSlug,
            $regionId === null ? '' : ' in region '.$regionId,
        ));

        return $exception->withContext([
            'package' => $packageSlug,
            'region_id' => $regionId,
            // Flattened to a string because exception context is scalar-only,
            // and this has to survive into a log line intact.
            'rejections' => self::summarise($rejectionsByReason),
        ]);
    }

    public function errorCode(): string
    {
        return 'hosting.no_capacity_available';
    }

    /**
     * Capacity exhaustion is a temporary condition of the platform, not a
     * malformed request.
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
