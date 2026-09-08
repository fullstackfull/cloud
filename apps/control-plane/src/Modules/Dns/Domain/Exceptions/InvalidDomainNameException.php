<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A name a person typed that is not a name.
 *
 * Separate from {@see InvalidDnsZoneException}, which is a provider answering
 * with something unusable — that is a 502 and an operator's problem. This is a
 * 422 and the customer's, so it says which rule was broken rather than only
 * that one was.
 */
final class InvalidDomainNameException extends DomainException
{
    public static function malformed(string $value, string $because): self
    {
        $exception = new self(sprintf('"%s" is not a domain name: %s.', $value, $because));

        return $exception->withContext(['name' => $value, 'reason' => $because]);
    }

    public function errorCode(): string
    {
        return 'dns.invalid_name';
    }
}
