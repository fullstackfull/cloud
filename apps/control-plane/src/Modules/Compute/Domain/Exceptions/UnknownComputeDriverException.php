<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A cluster names a driver the platform has no adapter for.
 *
 * Almost always a cluster row written by a migration or an operator ahead of
 * the code that implements it; failing loudly beats silently skipping the
 * cluster during an inventory sync, which would make its nodes look absent and
 * their machines look like drift.
 */
final class UnknownComputeDriverException extends DomainException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $driver, array $known): self
    {
        $exception = new self(sprintf(
            'No compute adapter is registered for the driver "%s". Known drivers: %s.',
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
        return 'compute.unknown_driver';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
