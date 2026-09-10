<?php

declare(strict_types=1);

namespace Lynomia\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Lynomia\Modules\Identity\Domain\Exceptions\EmailAddressNotVerifiedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request from an account whose address has not been proved — and
 * says which of the two refusals it is.
 *
 * This replaces Laravel's EnsureEmailIsVerified for the API. The framework's
 * middleware aborts with a plain 403, which the renderer necessarily reports
 * as `auth.forbidden`: the same code a customer gets for asking to do
 * something their role does not allow. Those two answers need different
 * screens. One is a dead end the customer can do nothing about; the other is
 * a step in onboarding with an obvious next action, and showing the first
 * where the second belongs is how a new customer decides the product is
 * broken.
 *
 * A guest is not this middleware's business: `auth` runs first and has
 * already answered 401. It is only reached with a user in hand.
 */
final class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            throw EmailAddressNotVerifiedException::make((string) $user->getEmailForVerification());
        }

        return $next($request);
    }
}
