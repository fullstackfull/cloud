<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Exceptions;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The platform was asked for a backup it has nowhere to put.
 *
 * 500 rather than 422: nothing about the request is wrong. A cluster with no
 * backup datastore declared is a deployment that has not finished, and the
 * customer cannot fix it.
 *
 * The context names the configuration key, never what it points at.
 */
final class BackupNotConfiguredException extends DomainException
{
    /**
     * The cluster has no datastore declared.
     *
     * Nothing identifying the platform's own infrastructure goes in the
     * context. The API renderer publishes a DomainException's context verbatim
     * as `error.details`, so a configuration key or a cluster id put here
     * reaches the customer — and "backups.datastores.kw-cluster" tells them
     * the name of the cluster their neighbours are on and the shape of the
     * platform's configuration.
     *
     * Both go to the log instead, where the audience is somebody holding a
     * runbook. The customer gets a sentence that says it is our problem and
     * that somebody knows.
     */
    public static function missingDatastore(string $clusterId, string $key): self
    {
        Log::warning('A backup was requested for a cluster with no datastore configured.', [
            'cluster_id' => $clusterId,
            'configuration_key' => $key,
        ]);

        return new self(
            'Backups are not available for this service yet: no backup datastore is configured '
            .'for the cluster it runs on. This has been recorded.'
        );
    }

    public static function notBackable(string $serviceId, string $because): self
    {
        $exception = new self(sprintf('This service cannot be backed up: %s', $because));

        return $exception->withContext(['service_id' => $serviceId, 'reason' => $because]);
    }

    public function errorCode(): string
    {
        return 'backups.not_configured';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
