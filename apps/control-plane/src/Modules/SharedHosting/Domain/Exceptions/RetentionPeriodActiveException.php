<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A suspended account was asked to be terminated before its retention window
 * elapsed.
 *
 * The window is the commercial safety net, not a technical one. Most
 * suspensions are billing disputes that end with the customer paying, and the
 * platform has no way to distinguish "cancelled" from "paying next Tuesday" at
 * the moment a dunning run fires. Terminating early turns a recoverable
 * invoice into a lost customer, an unrecoverable dataset and a liability —
 * and unlike a suspension, it cannot be undone by an API call.
 */
final class RetentionPeriodActiveException extends DomainException
{
    public static function forAccount(string $accountId, string $username, string $releasesAt): self
    {
        $exception = new self(sprintf(
            'The hosting account %s is inside its retention window and may not be terminated until %s.',
            $username,
            $releasesAt,
        ));

        return $exception->withContext([
            'account_id' => $accountId,
            'username' => $username,
            'retention_releases_at' => $releasesAt,
        ]);
    }

    public function errorCode(): string
    {
        return 'hosting.retention_period_active';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
