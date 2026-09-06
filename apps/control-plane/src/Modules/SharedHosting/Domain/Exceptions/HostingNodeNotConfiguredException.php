<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A node row names a panel the platform cannot reach.
 *
 * Separated from a provider failure because nothing was attempted: there is no
 * endpoint to call, or no credential to call it with. Retrying cannot help,
 * and the fix is a configuration change rather than an operational one.
 *
 * Credentials are read from configuration and never from the node row, which
 * holds only a reference to them. A token in the database is a token in every
 * backup, every replica and every support export — and a WHM root API token is
 * root on a machine holding several hundred customers' websites and mail.
 */
final class HostingNodeNotConfiguredException extends DomainException
{
    private string $errorCode = 'hosting.node_not_configured';

    public static function missingEndpoint(string $nodeId, string $hostname): self
    {
        $exception = new self(sprintf(
            'Hosting node %s has no API endpoint configured.',
            $hostname,
        ));

        $exception->errorCode = 'hosting.node_endpoint_missing';

        return $exception->withContext(['node_id' => $nodeId, 'hostname' => $hostname]);
    }

    public static function missingCredentials(string $nodeId, string $hostname, string $reference): self
    {
        $exception = new self(sprintf(
            'Hosting node %s has no credentials under the reference "%s".',
            $hostname,
            $reference,
        ));

        $exception->errorCode = 'hosting.node_credentials_missing';

        // The reference is a config key name, not a secret. The credential it
        // points at is never read here and never travels in an exception.
        return $exception->withContext([
            'node_id' => $nodeId,
            'hostname' => $hostname,
            'credentials_reference' => $reference,
        ]);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
