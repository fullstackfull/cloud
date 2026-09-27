<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * The middleware a route runs, as the router will run it.
 *
 * Route::gatherMiddleware() is what a route declares — its groups' and its
 * own entries, as written — and it keeps an entry that
 * `->withoutMiddleware(...)` removes from what runs. An oracle that reads it
 * passes a route whose guard has been excluded (OB5-2, re-audit of round
 * four: `->withoutMiddleware('staff')` on audit.index left the staff-gate test
 * green while a customer read GET /api/admin/audit).
 *
 * This returns Router::gatherRouteMiddleware(): groups expanded, aliases
 * resolved to class names with their parameters (`permission:x` becomes
 * `Spatie\Permission\Middleware\PermissionMiddleware:x`, `throttle:api`
 * becomes the throttle class the `throttle` alias names, followed by `:api`),
 * every excluded entry removed, and the list sorted into the kernel's
 * middleware priority. Closures are dropped; only strings are returned.
 */
trait ReadsTheMiddlewareARouteRuns
{
    /**
     * @return list<string>
     */
    protected function middlewareTheRouteRuns(Route $route): array
    {
        return array_values(array_filter(
            app(Router::class)->gatherRouteMiddleware($route),
            'is_string',
        ));
    }

    /**
     * The parameter lists of every entry in what the route runs whose class
     * is `$class` or a subclass of it — `['api']` for `ThrottleRequests:api`,
     * `['30', '1', 'wallet-credit:']` for `ThrottleRequests:30,1,wallet-credit:`.
     * An entry with no parameters yields `[]`.
     *
     * @param  class-string  $class
     * @return list<list<string>>
     */
    protected function parametersOf(Route $route, string $class): array
    {
        $found = [];

        foreach ($this->middlewareTheRouteRuns($route) as $entry) {
            [$name, $parameters] = array_pad(explode(':', $entry, 2), 2, null);

            if (! is_a($name, $class, true)) {
                continue;
            }

            $found[] = $parameters === null || $parameters === '' ? [] : explode(',', $parameters);
        }

        return $found;
    }
}
