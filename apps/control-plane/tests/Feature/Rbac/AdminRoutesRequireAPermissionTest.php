<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Lynomia\Http\Middleware\ResolveActingCustomer;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\Support\ReadsTheMiddlewareARouteRuns;
use Tests\TestCase;

/**
 * The administrative surface is separated from the customer surface by the
 * permission on each route and by the staff gate, not by the prefix it sits
 * under.
 *
 * `/api/admin` and `/api/v1` authenticate with the same guard, so a customer's
 * session or personal access token reaches both. The prefix is signposting.
 * The staff gate (EnsureTheCallerIsStaff; held on every route by
 * TheCustomerRoleIsNotAWayIntoTheAdminSurfaceTest) refuses a login with no
 * staff role; the permission decides which staff may do what. A route added
 * to the admin file without a permission is therefore not "missing a
 * nice-to-have" — it is an endpoint every operator reaches whatever their
 * role, and one step (the staff gate) from every customer. The only reliable
 * way to keep that from happening on a busy afternoon is to fail the build.
 *
 * What every test here reads, for each route whose URI starts `api/admin`:
 * the middleware the router runs for it (ReadsTheMiddlewareARouteRuns —
 * Router::gatherRouteMiddleware(): aliases resolved to classes with their
 * parameters, `withoutMiddleware()` exclusions removed). Not what the route
 * declares: Route::gatherMiddleware() kept a `permission:` entry that
 * `->withoutMiddleware(...)` had removed from what runs, and missed one
 * spelled by class name rather than alias (the OB5-2 class, re-audit of
 * round four). A permission is an entry whose class is spatie's
 * PermissionMiddleware; the acting-customer scope is an entry whose class is
 * ResolveActingCustomer.
 */
final class AdminRoutesRequireAPermissionTest extends TestCase
{
    use ReadsTheMiddlewareARouteRuns;

    #[Test]
    public function every_admin_route_names_the_permission_it_requires(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }

            if ($this->parametersOf($route, PermissionMiddleware::class) === []) {
                $unguarded[] = $this->describe($route);
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "Administrative routes that name no permission:\n  ".implode("\n  ", $unguarded),
        );
    }

    #[Test]
    public function every_permission_an_admin_route_names_actually_exists(): void
    {
        // A typo in a permission name does not fail loudly: spatie's middleware
        // denies an unknown permission to everyone, so the endpoint looks
        // secured and is simply broken for the people who should reach it. That
        // gets "fixed" under pressure by removing the middleware.
        $known = array_map(static fn (Permission $case): string => $case->value, Permission::cases());
        $unknown = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }

            foreach ($this->parametersOf($route, PermissionMiddleware::class) as $parameters) {
                $names = $parameters === [] ? [''] : explode('|', $parameters[0]);

                foreach ($names as $name) {
                    if (! in_array(trim($name), $known, true)) {
                        $unknown[] = $this->describe($route).' → '.trim($name);
                    }
                }
            }
        }

        $this->assertSame([], $unknown, 'Admin routes naming permissions that do not exist: '.implode(', ', $unknown));
    }

    #[Test]
    public function no_admin_route_carries_the_acting_customer_middleware(): void
    {
        // An administrator acts on the platform, not on behalf of one account.
        // Scoping them to a tenant would silently hide every other customer,
        // which reads in the interface as "the data is gone".
        $scoped = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }

            if ($this->parametersOf($route, ResolveActingCustomer::class) !== []) {
                $scoped[] = $this->describe($route);
            }
        }

        $this->assertSame([], $scoped);
    }

    private function describe(RoutingRoute $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri();
    }
}
