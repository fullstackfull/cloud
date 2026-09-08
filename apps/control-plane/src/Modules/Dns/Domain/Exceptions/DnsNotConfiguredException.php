<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The platform is configured for a DNS provider it cannot authenticate to, or
 * asked for a capability the configured account does not hold.
 *
 * 500 and not 422: nothing about the request is wrong. The deployment is
 * incomplete, and the only person who can fix it works here.
 *
 * The context names the configuration key, never the credential it points at.
 */
final class DnsNotConfiguredException extends DomainException
{
    public static function missingCredentials(string $provider, string $key): self
    {
        $exception = new self(sprintf('The %s DNS provider has no API token configured under "%s".', $provider, $key));

        return $exception->withContext(['provider' => $provider, 'configuration_key' => $key]);
    }

    /**
     * Zone creation needs an account to create the zone in, and an account id
     * is not something to guess: creating a zone in the wrong account is not
     * reversible from the platform's side.
     */
    public static function cannotCreateZones(string $provider, string $key): self
    {
        $exception = new self(sprintf(
            'The %s DNS provider cannot create zones: no account identifier is configured under "%s".',
            $provider,
            $key,
        ));

        return $exception->withContext(['provider' => $provider, 'configuration_key' => $key]);
    }

    public function errorCode(): string
    {
        return 'dns.not_configured';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
