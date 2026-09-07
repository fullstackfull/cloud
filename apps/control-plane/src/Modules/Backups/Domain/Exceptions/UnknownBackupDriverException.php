<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * `config('billing.providers.backup')` names a driver this build does not have.
 *
 * Unreachable in production, where the boot guard refuses to start a deployment
 * configured for a driver that is not here. It remains for development and test,
 * which the guard does not cover.
 */
final class UnknownBackupDriverException extends DomainException
{
    /**
     * @param  list<string>  $available
     */
    public static function named(string $driver, array $available): self
    {
        $exception = new self(sprintf(
            'No backup adapter is registered for the driver "%s". This build contains: %s.',
            $driver,
            implode(', ', $available),
        ));

        return $exception->withContext(['driver' => $driver, 'available' => implode(', ', $available)]);
    }

    public function errorCode(): string
    {
        return 'backups.unknown_driver';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
