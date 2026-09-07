<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The administrative surface is separated from the customer surface by the
 * permission on each route, not by the prefix it sits under.
 *
 * `/api/admin` and `/api/v1` authenticate with the same guard, so a customer's
 * session or personal access token reaches both. The prefix is signposting; the
 * permission is the control. A route added to the admin file without one is
 * therefore not "missing a nice-to-have" — it is a customer-reachable
 * administrative endpoint, and the only reliable way to keep that from
 * happening on a busy afternoon is to fail the build.
 */
final class AdminRoutesRequireAPermissionTest extends TestCase
{
    #[Test]
    public function every_admin_route_names_the_permission_it_requires(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }

            $declares = array_filter(
                $route->gatherMiddleware(),
                static fn ($middleware): bool => is_string($middleware)
                    && str_starts_with($middleware, 'permission:'),
            );

            if ($declares === []) {
                $unguarded[] = $this->describe($route);
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "Administrative routes reachable by any verified customer:\n  ".implode("\n  ", $unguarded),
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

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'permission:')) {
                    continue;
                }

                foreach (explode('|', substr($middleware, strlen('permission:'))) as $name) {
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

            if (in_array('customer', $route->gatherMiddleware(), true)) {
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
