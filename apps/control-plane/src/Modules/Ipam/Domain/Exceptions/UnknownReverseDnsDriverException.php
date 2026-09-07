<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The configured DNS driver has no adapter in this build.
 *
 * `config('billing.providers.dns')` names the driver. This deployment ships the
 * fake and nothing else: the Cloudflare adapter is not written, because its
 * credentials are unavailable here and an untested API client that reports
 * records as published without publishing them is worse than a missing one.
 *
 * 500 rather than a customer-facing status, because it is a deployment
 * mistake — the platform is configured for a provider it cannot drive — and it
 * must read as the platform's fault in the logs and the alert.
 */
final class UnknownReverseDnsDriverException extends DomainException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $driver, array $known): self
    {
        $exception = new self(sprintf(
            'No reverse-DNS adapter is registered for the driver "%s"; this build knows %s.',
            $driver,
            implode(', ', $known),
        ));

        return $exception->withContext([
            'driver' => $driver,
            'known_drivers' => implode(', ', $known),
        ]);
    }

    public function errorCode(): string
    {
        return 'ipam.unknown_reverse_dns_driver';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
