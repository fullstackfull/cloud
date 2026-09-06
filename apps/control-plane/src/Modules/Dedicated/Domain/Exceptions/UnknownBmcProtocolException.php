<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * An endpoint row names a protocol the running code has no adapter for.
 *
 * Almost always a row written ahead of the adapter that implements it. Failing
 * loudly beats skipping the endpoint during an inventory sync, which would
 * make a working machine look unreachable and, eventually, look failed.
 */
final class UnknownBmcProtocolException extends DomainException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $protocol, array $known): self
    {
        $exception = new self(sprintf(
            'No BMC adapter is registered for the protocol "%s". Known protocols: %s.',
            $protocol,
            implode(', ', $known),
        ));

        return $exception->withContext([
            'protocol' => $protocol,
            'known_protocols' => implode(', ', $known),
        ]);
    }

    public function errorCode(): string
    {
        return 'dedicated.unknown_bmc_protocol';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
