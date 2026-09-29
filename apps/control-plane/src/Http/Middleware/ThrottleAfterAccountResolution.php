<?php

declare(strict_types=1);

namespace Lynomia\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * A named rate limiter that runs where the route declares it.
 *
 * The router does not run a route's middleware in the order the route file
 * writes it. It sorts the stack against Laravel's middleware priority list,
 * and `ThrottleRequests` is on that list while `ResolveActingCustomer` is
 * not: the sort lifts every throttle above the `api` group's
 * `SubstituteBindings`, and so above `verified` and `customer`, which follow
 * it. A route that writes `customer` and then `throttle:<name>` runs the
 * throttle first.
 *
 * For most limiters that is the right place — they key on the user, and
 * refusing early is cheap. For a limiter keyed on the acting account it is
 * the wrong one: the account has not been resolved when the closure runs, and
 * the closure falls back to the user. The invitation limiter did exactly
 * that, so an account's budget was multiplied by the number of its
 * administrators, while its own docblock said the account was already
 * settled.
 *
 * This class is not a `ThrottleRequests` subclass on purpose: the sorter
 * matches a middleware's parents and interfaces as well as its own name, and
 * anything it matches moves. Composed rather than inherited, it stays exactly
 * where it is declared and hands the request to the framework's throttle
 * unchanged — the same limiter lookup, the same 429, the same headers.
 *
 * Use it only for a limiter that needs the acting account, and declare it
 * after `customer`. Everything else belongs on the plain `throttle:` alias.
 */
final class ThrottleAfterAccountResolution
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next, string $limiter): Response
    {
        return $this->throttle->handle($request, $next, $limiter);
    }
}
