<?php

declare(strict_types=1);

namespace Lynomia\Modules\Shared\Domain\Exceptions;

use Throwable;

final class InvalidMoneyException extends DomainException
{
    public static function forMinorUnits(int $minorUnits, string $currency, ?Throwable $previous = null): self
    {
        $exception = new self(
            sprintf('Cannot build money from %d minor units of "%s".', $minorUnits, $currency),
            previous: $previous,
        );

        return $exception->withContext(['minor_units' => $minorUnits, 'currency' => $currency]);
    }

    public static function forAmount(string $amount, string $currency, ?Throwable $previous = null): self
    {
        $exception = new self(
            sprintf('Cannot build money from amount "%s" of "%s".', $amount, $currency),
            previous: $previous,
        );

        return $exception->withContext(['amount' => $amount, 'currency' => $currency]);
    }

    public static function forAllocation(int $parts): self
    {
        $exception = new self(sprintf('Cannot allocate money into %d parts; at least 1 is required.', $parts));

        return $exception->withContext(['parts' => $parts]);
    }

    public function errorCode(): string
    {
        return 'money.invalid_amount';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
