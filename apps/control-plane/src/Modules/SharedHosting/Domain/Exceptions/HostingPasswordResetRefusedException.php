<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A password reset for an account the panel no longer holds.
 *
 * A terminated account has had everything released at the panel, and a failed
 * one never had anything there. Asking the panel to set a password on either
 * would be a request about an account that does not exist, answered as a 502
 * that looks like an outage — so the platform refuses from its own record
 * first, and says why.
 *
 * A PENDING account is not refused. Pending is exactly the state a lost
 * `createacct` answer leaves: the panel may hold the account under a password
 * nobody has, and that is the case the reset exists for.
 */
final class HostingPasswordResetRefusedException extends DomainException
{
    public static function becauseThePanelNoLongerHoldsIt(string $accountId, string $status): self
    {
        $exception = new self(sprintf(
            'This hosting account is %s, so the panel no longer holds it and there is no password to set.',
            $status,
        ));

        return $exception->withContext(['hosting_account_id' => $accountId, 'status' => $status]);
    }

    public function errorCode(): string
    {
        return 'hosting.password_reset_refused';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
