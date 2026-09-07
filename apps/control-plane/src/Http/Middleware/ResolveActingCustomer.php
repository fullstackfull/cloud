<?php

declare(strict_types=1);

namespace Lynomia\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Lynomia\Modules\ApiKeys\Infrastructure\Models\PersonalAccessToken;
use Lynomia\Modules\Identity\Domain\Exceptions\ActingCustomerException;
use Lynomia\Modules\Identity\Domain\Services\ActingCustomer;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides, once per request, which customer account the caller is acting for.
 *
 * This is the single enforcement point for multi-tenancy. Every customer-scoped
 * endpoint reads the answer from here and never from its own parameters, which
 * is what makes cross-tenant access a wiring mistake that shows up immediately
 * rather than an authorisation check somebody forgot to write.
 *
 * The order of precedence matters and is not negotiable:
 *
 *   1. An API token bound to a customer decides, full stop. The binding is the
 *      point of issuing a scoped token, so a header must not be able to widen
 *      it — a token issued for one account would otherwise reach every account
 *      its owner belongs to, which is the opposite of a scope.
 *   2. Otherwise the `X-Lynomia-Customer` header names the account, and
 *      membership is checked against accepted memberships only.
 *   3. Otherwise, if the login belongs to exactly one account, that one.
 *   4. Otherwise the request is refused. Guessing between two accounts is how
 *      an invoice gets paid from the wrong balance.
 */
final class ResolveActingCustomer
{
    public function __construct(private readonly ActingCustomer $acting) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            // Unauthenticated requests never reach here: the route group
            // authenticates first. If one does, refusing is the only safe
            // answer.
            throw ActingCustomerException::noAccount();
        }

        $this->acting->set($this->resolve($request, $user));

        return $next($request);
    }

    private function resolve(Request $request, User $user): Customer
    {
        $requested = $this->requestedCustomerId($request);
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken && $token->customer_id !== null) {
            $scoped = (string) $token->customer_id;

            // A header that names a different account is refused rather than
            // ignored: silently acting on the token's account would answer a
            // question the caller did not ask, and on a billing API that is how
            // the wrong customer gets charged.
            if ($requested !== null && $requested !== $scoped) {
                throw ActingCustomerException::outsideTokenScope();
            }

            // Membership is re-checked even for a scoped token. A token
            // outlives the membership that justified it, and a former employee
            // whose access was removed must lose it everywhere at once.
            return $this->membershipOf($user, $scoped);
        }

        if ($requested !== null) {
            return $this->membershipOf($user, $requested);
        }

        $memberships = $user->customers()->get();

        return match ($memberships->count()) {
            0 => throw ActingCustomerException::noAccount(),
            1 => $memberships->first(),
            default => throw ActingCustomerException::ambiguous($memberships->count()),
        };
    }

    /**
     * The header, validated only for shape.
     *
     * A malformed value is treated as absent rather than as an error, so that a
     * proxy injecting a stray header cannot make every request fail. It will be
     * checked against membership either way, so nothing is trusted here.
     */
    private function requestedCustomerId(Request $request): ?string
    {
        $value = $request->header('X-Lynomia-Customer');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        // Lower-cased, not upper. A ULID is case-insensitive by
        // specification, but the column stores exactly what Str::ulid()
        // produced, which is lower case, and Postgres compares strings byte by
        // byte - so a caller who sends the canonical upper-case form would
        // otherwise match nothing and be told they are not a member.
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $value) === 1 ? strtolower($value) : null;
    }

    private function membershipOf(User $user, string $customerId): Customer
    {
        /*
         * Resolved through the user's own accepted memberships rather than by
         * loading the customer and then checking. The query cannot return an
         * account the user is not a member of, so there is no window in which
         * an unscoped model is in hand.
         */
        $customer = $user->customers()->whereKey($customerId)->first();

        if (! $customer instanceof Customer) {
            throw ActingCustomerException::notAMember();
        }

        return $customer;
    }
}
