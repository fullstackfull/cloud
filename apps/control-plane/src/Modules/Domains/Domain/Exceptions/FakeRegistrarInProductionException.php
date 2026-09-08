<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * The fake registrar was reached for in production.
 *
 * A third copy of a shape the Compute and Payments modules already have, and
 * duplicated rather than shared on purpose: each module owns the guard for its
 * own provider boundary, so that adding a module never means editing a class
 * two other modules depend on. The alternative — one shared exception — is a
 * seam between unrelated provider families that nothing needs.
 */
final class FakeRegistrarInProductionException extends DomainException
{
    public static function forProvider(string $name): self
    {
        return (new self(sprintf(
            'The %s registrar must never be used in production: it reports domains as registered without registering them.',
            $name,
        )))->withContext(['provider' => $name]);
    }

    public function errorCode(): string
    {
        return 'domain.fake_registrar_in_production';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
