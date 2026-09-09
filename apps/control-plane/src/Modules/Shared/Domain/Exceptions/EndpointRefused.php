<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Exceptions;

/**
 * An address the platform will not be pointed at.
 *
 * 409 rather than 422: the request was well-formed, and the refusal is a
 * policy about where this control plane may open connections, not about the
 * shape of the field.
 */
final class EndpointRefused extends DomainException
{
    public static function because(string $endpoint, string $reason): self
    {
        return (new self(sprintf('%s is refused: %s', $endpoint, $reason)))->withContext(['endpoint' => $endpoint]);
    }

    public function errorCode(): string
    {
        return 'endpoint_refused';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
