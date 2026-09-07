<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Domain\Exceptions;

use Lynomia\Modules\Ipam\Domain\ValueObjects\Hostname;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A string offered as a reverse-DNS target was not a hostname.
 *
 * Raised by {@see Hostname}, which
 * every path to a DNS provider goes through. The reason is carried in the
 * context rather than only in the prose, so a client can show the customer
 * which rule their name broke without matching on a sentence.
 */
final class InvalidHostnameException extends DomainException
{
    public static function because(string $hostname, string $reason): self
    {
        $exception = new self(sprintf('"%s" is not a valid hostname: %s.', $hostname, $reason));

        return $exception->withContext(['hostname' => $hostname, 'reason' => $reason]);
    }

    public function errorCode(): string
    {
        return 'ipam.invalid_hostname';
    }
}
