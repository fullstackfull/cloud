<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Lynomia\Http\Middleware\EnsureEmailIsVerified;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ReadsTheMiddlewareARouteRuns;
use Tests\TestCase;

/**
 * Every /api/admin route runs `verified` after authentication (B2, re-audit
 * after round five, unnumbered).
 *
 * Nothing held it. Dropping `verified` from the support admin group left 1072
 * tests green: EmailVerificationEnforcementTest proves the middleware on a
 * route of its own, not that the admin surface carries it. And `verified` is
 * load-bearing there: an operator invitation promotes an existing login
 * without proving its mailbox (InviteOperator), so between the invitation and
 * the reset that proves it, the only thing between that login and the admin
 * surface is this middleware.
 *
 * What it reads: for every route in the route table whose URI starts
 * `api/admin`, the list ReadsTheMiddlewareARouteRuns returns — the middleware
 * the router runs (Router::gatherRouteMiddleware(): groups expanded, aliases
 * resolved to class names with their parameters, `withoutMiddleware()`
 * exclusions removed, sorted into the kernel's priority). A route passes when
 * that list holds an entry whose class is Illuminate's Authenticate (or a
 * subclass), with any guard list or none, and after it an entry that is
 * exactly Lynomia\Http\Middleware\EnsureEmailIsVerified, the class the
 * `verified` alias names. It reads route registration, not requests; the
 * behaviour is held by
 * tests/Feature/Rbac/AnUnverifiedOperatorIsRefusedTheAdminSurfaceTest.php.
 */
final class EveryAdminRouteRequiresAVerifiedAddressTest extends TestCase
{
    use ReadsTheMiddlewareARouteRuns;

    #[Test]
    public function every_admin_route_runs_verified_after_authentication(): void
    {
        $this->assertNotSame([], $this->adminRoutes(), 'No /api/admin route was read; the oracle proves nothing.');

        $this->assertSame([], $this->unverifiedAdminRoutes(), implode("\n  ", [
            'Admin routes that do not run `verified` (EnsureEmailIsVerified) after authentication:',
            ...$this->unverifiedAdminRoutes(),
        ]));
    }

    /**
     * The oracle's own positive control: an admin route that takes `verified`
     * off with withoutMiddleware(), declared at runtime beside the real ones,
     * is what the oracle above reports — so it is not green because it reads
     * nothing.
     */
    #[Test]
    public function an_admin_route_that_excludes_verified_is_reported(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'verified', 'staff', 'throttle:api'])
            ->get('api/admin/zz-oracle-probe-without-verified', static fn (): string => 'probe')
            ->withoutMiddleware('verified');

        Route::middleware(['api', 'auth:sanctum', 'staff', 'throttle:api'])
            ->get('api/admin/zz-oracle-probe-never-verified', static fn (): string => 'probe');

        Route::middleware(['api', 'verified', 'auth:sanctum', 'staff', 'throttle:api'])
            ->get('api/admin/zz-oracle-probe-verified-and-authenticated', static fn (): string => 'probe');

        $reported = $this->unverifiedAdminRoutes();

        $this->assertContains('GET|HEAD api/admin/zz-oracle-probe-without-verified', $reported);
        $this->assertContains('GET|HEAD api/admin/zz-oracle-probe-never-verified', $reported);
        // Declared before auth, run after it: the kernel's priority sorts it.
        $this->assertNotContains('GET|HEAD api/admin/zz-oracle-probe-verified-and-authenticated', $reported);
    }

    /**
     * @return list<string>
     */
    private function unverifiedAdminRoutes(): array
    {
        $missing = [];

        foreach ($this->adminRoutes() as $route) {
            $authenticated = null;
            $verified = null;

            foreach ($this->middlewareTheRouteRuns($route) as $index => $entry) {
                $class = explode(':', $entry, 2)[0];

                if ($authenticated === null && is_a($class, Authenticate::class, true)) {
                    $authenticated = $index;
                }

                if ($entry === EnsureEmailIsVerified::class) {
                    $verified = $index;
                }
            }

            if ($authenticated === null || $verified === null || $verified < $authenticated) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        return $missing;
    }

    /**
     * @return list<RoutingRoute>
     */
    private function adminRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/admin'),
        ));
    }
}
