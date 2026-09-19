<?php

declare(strict_types=1);

namespace Lynomia\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Lynomia\Http\Support\RequestLocale;
use Symfony\Component\HttpFoundation\Response;

/**
 * Speaks the language the client asked for, for the length of one request.
 *
 * Registered globally rather than on a route group, for the same reason the
 * request id is: a 401 from the authentication layer and a 404 for a route
 * that does not exist are both produced before any group middleware runs, and
 * they are the responses a customer is most likely to read. The negotiation
 * itself lives in RequestLocale; this class only applies its answer to the
 * application, which is what `__()`, the validator and the exception renderer
 * read.
 *
 * Nothing about the account is consulted. A customer's stored preference
 * governs what the platform sends them unprompted — notifications, mail —
 * and the request header governs what it answers them with. The two can
 * legitimately differ for one session, and the request must win for the
 * response to match the screen that made it.
 */
final class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(RequestLocale::for($request));

        return $next($request);
    }
}
