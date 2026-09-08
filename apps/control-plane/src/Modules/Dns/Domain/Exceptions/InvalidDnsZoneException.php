<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A provider answered with a zone the platform cannot use.
 *
 * Raised while reading a provider response rather than while handling a
 * customer request, which is why it names the provider's own field: an
 * operator reading this needs to know which half of the pair was empty.
 */
final class InvalidDnsZoneException extends DomainException
{
    public static function missingIdentifier(string $name): self
    {
        $exception = new self(sprintf('The DNS provider returned a zone for "%s" with no identifier.', $name));

        return $exception->withContext(['zone' => $name]);
    }

    public static function missingName(string $id): self
    {
        $exception = new self('The DNS provider returned a zone with no name.');

        return $exception->withContext(['zone_id' => $id]);
    }

    public function errorCode(): string
    {
        return 'dns.invalid_zone';
    }

    public function httpStatus(): int
    {
        return 502;
    }
}
