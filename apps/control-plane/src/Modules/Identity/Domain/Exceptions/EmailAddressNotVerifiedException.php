<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Exceptions;

use Lynomia\Modules\Shared\Domain\Exceptions\DomainException;

/**
 * A signed-in customer whose address is not proved yet reached something that
 * needs a proved address.
 *
 * It has its own code because "we cannot do that for you" and "we do not know
 * yet that this is your address" are different situations with different next
 * steps, and the portal used to be unable to tell them apart: Laravel's own
 * middleware aborts with a bare 403, the renderer turned that into
 * `auth.forbidden`, and a customer three minutes into their first session was
 * shown a permissions message when what they needed was the resend button.
 *
 * The recovery path is named in the context so a client does not have to know
 * it: post to the resend endpoint, then follow the link in the mail.
 */
final class EmailAddressNotVerifiedException extends DomainException
{
    public static function make(string $email): self
    {
        $exception = new self('The address on this account is not verified yet.');

        return $exception->withContext([
            // The address the link went to, so a screen can say where to look
            // without a second request. It is the caller's own address: they
            // are authenticated, and it is already on their /me payload.
            'email' => $email,
            'resend_endpoint' => 'POST /api/v1/email/verify/resend',
        ]);
    }

    public function errorCode(): string
    {
        return 'auth.email_unverified';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
