<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Exceptions;

use RuntimeException;

/**
 * Base class for every exception raised by domain logic.
 *
 * Domain exceptions carry a stable machine-readable code so the HTTP layer can
 * translate them into a consistent JSON error body without string matching on
 * messages.
 */
abstract class DomainException extends RuntimeException
{
    /** @var array<string, scalar|null> */
    protected array $context = [];

    /**
     * Stable, machine-readable identifier, e.g. "money.currency_mismatch".
     */
    abstract public function errorCode(): string;

    /**
     * HTTP status this exception should map to when it escapes to the API.
     */
    public function httpStatus(): int
    {
        return 422;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    protected function withContext(array $context): static
    {
        $this->context = $context;

        return $this;
    }
}
