<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Exceptions;

/**
 * Raised when two Money values of different currencies are combined.
 *
 * This is always a programming error rather than user input: the platform
 * never adds KWD to USD and silently picks one.
 */
final class CurrencyMismatchException extends DomainException
{
    public static function between(string $left, string $right): self
    {
        $exception = new self(
            sprintf('Cannot operate on money in %s and %s without an explicit conversion.', $left, $right)
        );

        return $exception->withContext(['left' => $left, 'right' => $right]);
    }

    public function errorCode(): string
    {
        return 'money.currency_mismatch';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
