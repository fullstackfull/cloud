<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Exceptions;

use Carbon\CarbonInterface;
use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

final class AccountLockedException extends DomainException
{
    public static function until(CarbonInterface $until): self
    {
        $exception = new self(
            'This account is temporarily locked after repeated failed sign-in attempts.'
        );

        return $exception->withContext([
            'locked_until' => $until->toIso8601String(),
            'retry_after_seconds' => max(0, $until->diffInSeconds(now(), absolute: true)),
        ]);
    }

    public function errorCode(): string
    {
        return 'auth.account_locked';
    }

    public function httpStatus(): int
    {
        return 423;
    }
}
