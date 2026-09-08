<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A TLD names a registrar this build does not contain.
 *
 * A 500 and a loud one. It means a row in the catalogue points at nothing,
 * which is a deployment error rather than a customer's mistake — and the
 * customer's symptom would otherwise be a search that silently returns
 * nothing for a namespace the platform advertises.
 */
final class UnknownRegistrarDriverException extends DomainException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $driver, array $known): self
    {
        return (new self(sprintf(
            'No registrar driver named "%s". This build has: %s.',
            $driver,
            implode(', ', $known),
        )))->withContext(['driver' => $driver]);
    }

    public function errorCode(): string
    {
        return 'domain.unknown_registrar_driver';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
