<?php

declare(strict_types=1);

namespace Lynomia\Modules\Domains\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;
use Throwable;

/**
 * A registrar refused, or could not be reached.
 *
 * ---------------------------------------------------------------------------
 * The distinction this class exists to carry
 * ---------------------------------------------------------------------------
 *
 * `$indeterminate` is the whole point. A registrar that answers "this name is
 * taken" has told the platform something final, and the customer's money can
 * go back. A registrar that does not answer has told it nothing: it may hold
 * the name, it may have charged for it, and asking again is how somebody buys
 * two years of a domain they wanted one of.
 *
 * Adapters must set it. Anything that catches this and treats both the same
 * has thrown away the only fact that decides what happens next.
 */
final class DomainRegistrarException extends DomainException
{
    private bool $indeterminate = false;

    public static function refused(string $message, ?Throwable $previous = null): self
    {
        return new self($message, previous: $previous);
    }

    /**
     * The call did not answer, or answered in a way that settles nothing.
     */
    public static function indeterminate(string $message, ?Throwable $previous = null): self
    {
        $exception = new self($message, previous: $previous);
        $exception->indeterminate = true;

        return $exception;
    }

    public function isIndeterminate(): bool
    {
        return $this->indeterminate;
    }

    public function errorCode(): string
    {
        return $this->indeterminate ? 'domain.registrar_unreachable' : 'domain.registrar_refused';
    }

    public function httpStatus(): int
    {
        return 502;
    }
}
