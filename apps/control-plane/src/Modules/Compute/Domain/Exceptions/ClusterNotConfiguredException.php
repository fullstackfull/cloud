<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A cluster row cannot be turned into a working connection.
 *
 * Almost always a cluster created in the database before its credentials were
 * deployed. Failing here — loudly, with the missing key named — beats the
 * alternative of an adapter constructed with an empty token, which produces a
 * 401 from the hypervisor on every operation and reads in the logs like the
 * cluster revoked our access.
 */
final class ClusterNotConfiguredException extends DomainException
{
    public static function missingCredentials(string $clusterId, string $reference): self
    {
        $exception = new self(sprintf(
            'No credentials are configured for cluster %s. Expected them under compute.credentials.%s.',
            $clusterId,
            $reference,
        ));

        return $exception->withContext([
            'cluster_id' => $clusterId,
            'credentials_reference' => $reference,
        ]);
    }

    public static function missingEndpoint(string $clusterId): self
    {
        $exception = new self(sprintf('Cluster %s has no API endpoint.', $clusterId));

        return $exception->withContext(['cluster_id' => $clusterId]);
    }

    public function errorCode(): string
    {
        return 'compute.cluster_not_configured';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
