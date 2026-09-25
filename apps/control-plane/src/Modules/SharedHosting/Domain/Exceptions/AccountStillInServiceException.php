<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A termination was asked for an account that is not waiting to be released.
 *
 * The path to termination runs through suspension, so that the retention
 * window stands between a late invoice and a deleted website. An account that
 * is serving, still being built, or whose build failed has no window running
 * at all — refusing it is not a matter of dates, and it is refused before any
 * date is read, because a serving account can carry a stale `suspended_at`
 * whose "window" elapsed months ago.
 *
 * The only way past it is `force`, which is granted only to somebody holding
 * both `hosting_account.manage` and `service.terminate` — by the hosting-account
 * route's controller, and by the service route's EndOfService::authorityOver(),
 * which asks for both whether or not `force` is sent.
 */
final class AccountStillInServiceException extends DomainException
{
    public static function forAccount(string $accountId, string $username, string $status): self
    {
        $exception = new self(sprintf(
            'The hosting account %s is %s, not suspended, and an account is suspended before it is terminated.',
            $username,
            $status,
        ));

        return $exception->withContext([
            'account_id' => $accountId,
            'username' => $username,
            'status' => $status,
        ]);
    }

    public function errorCode(): string
    {
        return 'hosting.termination_before_suspension';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
