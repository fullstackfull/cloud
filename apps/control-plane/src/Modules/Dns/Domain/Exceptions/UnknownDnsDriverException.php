<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * `config('billing.providers.dns')` names a driver this build does not contain.
 *
 * In production this cannot be reached: the boot guard refuses to start a
 * deployment configured for a driver that is not here, precisely so the failure
 * arrives during a deploy rather than during a customer's request. It remains
 * for the development and test environments the guard does not cover.
 */
final class UnknownDnsDriverException extends DomainException
{
    /**
     * @param  list<string>  $available
     */
    public static function named(string $driver, array $available): self
    {
        $exception = new self(sprintf(
            'No DNS adapter named "%s" is available. This build contains: %s.',
            $driver,
            implode(', ', $available),
        ));

        return $exception->withContext(['driver' => $driver, 'available' => implode(', ', $available)]);
    }

    public function errorCode(): string
    {
        return 'dns.unknown_driver';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
