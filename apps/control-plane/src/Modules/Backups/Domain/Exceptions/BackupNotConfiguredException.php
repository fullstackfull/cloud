<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Exceptions;

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
    public static function missingDatastore(string $clusterId, string $key): self
    {
        $exception = new self(
            'Backups are not available for this service yet: no backup datastore is configured '
            .'for the cluster it runs on. This has been recorded.'
        );

        return $exception->withContext(['cluster_id' => $clusterId, 'configuration_key' => $key]);
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
