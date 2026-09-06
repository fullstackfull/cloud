<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A BMC endpoint row exists but the platform cannot build a working connection
 * from it.
 *
 * Failing loudly beats degrading. The tempting fallbacks — try the vendor
 * default credentials, skip TLS verification because the certificate is
 * self-signed, guess the port — each end at the same place: a complete
 * out-of-band computer with power control and virtual media, reachable by
 * whoever answers the connection.
 */
final class BmcNotConfiguredException extends DomainException
{
    public static function missingAddress(string $endpointId): self
    {
        $exception = new self(sprintf('BMC endpoint %s has no address.', $endpointId));

        return $exception->withContext(['bmc_endpoint_id' => $endpointId]);
    }

    /**
     * @param  string  $reference  The credentials_reference the row names. Safe to log: it is a
     *                             configuration key, never a credential.
     */
    public static function missingCredentials(string $endpointId, string $reference): self
    {
        $exception = new self(sprintf(
            'No BMC credentials are configured under "%s" for endpoint %s. '
            .'Credentials are read from configuration and are never stored on the row.',
            $reference,
            $endpointId,
        ));

        return $exception->withContext([
            'bmc_endpoint_id' => $endpointId,
            'credentials_reference' => $reference,
        ]);
    }

    public static function noEndpoint(string $serverId): self
    {
        $exception = new self(sprintf(
            'Server %s has no BMC endpoint, so it cannot be reached out of band.',
            $serverId,
        ));

        return $exception->withContext(['dedicated_server_id' => $serverId]);
    }

    /**
     * An adapter built for one machine was handed another machine's endpoint.
     *
     * This is caught at the boundary rather than trusted, because the failure
     * it prevents is unrecoverable: powering off, resetting or PXE booting the
     * wrong physical server. A wrong VM id destroys one customer's machine; a
     * wrong BMC address reinstalls a machine nobody asked about.
     */
    public static function endpointMismatch(string $boundEndpointId, string $givenEndpointId): self
    {
        $exception = new self(
            'This BMC adapter was built for a different endpoint than the one it was asked to operate on. '
            .'Refusing rather than risking an operation against the wrong physical machine.'
        );

        return $exception->withContext([
            'bound_bmc_endpoint_id' => $boundEndpointId,
            'given_bmc_endpoint_id' => $givenEndpointId,
        ]);
    }

    public function errorCode(): string
    {
        return 'dedicated.bmc_not_configured';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
