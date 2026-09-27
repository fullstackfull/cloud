<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Lynomia\Http\Middleware\ThrottleAfterAccountResolution;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ReadsTheMiddlewareARouteRuns;
use Tests\TestCase;

/**
 * Regression: every authenticated API route was unthrottled, because the `api`
 * limiter defined in RateLimitServiceProvider was never attached to a route or
 * a group. Together with a bare Hash::check on the endpoints that re-confirm
 * the account password, that gave anyone holding a stolen session cookie or a
 * leaked token an unlimited, lockout-free password oracle on the endpoint that
 * turns the second factor off.
 *
 * The route-table tests here read the middleware the router runs for a route
 * (ReadsTheMiddlewareARouteRuns — Router::gatherRouteMiddleware(): aliases
 * resolved to classes with their parameters, `withoutMiddleware()` exclusions
 * removed, sorted into the kernel's priority), not what the route declares:
 * Route::gatherMiddleware() kept `throttle:api` on a route that
 * `->withoutMiddleware('throttle:api')` had taken it off (the OB5-2 class,
 * re-audit of round four). Authentication is an entry whose class is
 * Illuminate's Authenticate (or a subclass), whatever guard list follows it —
 * `auth:sanctum`, `auth:sanctum,web` — or none, as the bare `auth` alias
 * resolves. It used to be exactly `Authenticate:sanctum`, so a route declared
 * `auth:sanctum,web` with no throttle passed (X2, re-audit after round five);
 * the positive control below registers such routes and requires them
 * reported. A throttle is an entry whose class is ThrottleRequests (or a
 * subclass) or ThrottleAfterAccountResolution.
 */
final class EveryAuthenticatedRouteIsThrottledTest extends TestCase
{
    use ReadsTheMiddlewareARouteRuns;
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-9';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function every_authenticated_api_route_carries_a_throttle(): void
    {
        $unthrottled = $this->unthrottledAuthenticatedApiRoutes();

        $this->assertSame(
            [],
            $unthrottled,
            'Authenticated API routes reachable at unlimited rate: '.implode(', ', $unthrottled),
        );
    }

    /**
     * The oracle's positive control: unthrottled API routes authenticated by
     * `auth:sanctum,web` and by the bare `auth` alias, registered at runtime
     * beside the real ones, are what it reports — and the same route with a
     * throttle is not.
     */
    #[Test]
    public function an_unthrottled_route_is_reported_whatever_guards_authenticate_it(): void
    {
        Route::middleware(['api', 'auth:sanctum,web'])
            ->get('api/v1/zz-oracle-probe-two-guards', static fn (): string => 'probe');
        Route::middleware(['api', 'auth'])
            ->get('api/v1/zz-oracle-probe-bare-auth', static fn (): string => 'probe');
        Route::middleware(['api', 'auth:sanctum,web', 'throttle:api'])
            ->get('api/v1/zz-oracle-probe-throttled', static fn (): string => 'probe');

        $reported = $this->unthrottledAuthenticatedApiRoutes();

        $this->assertContains('api/v1/zz-oracle-probe-two-guards', $reported);
        $this->assertContains('api/v1/zz-oracle-probe-bare-auth', $reported);
        $this->assertNotContains('api/v1/zz-oracle-probe-throttled', $reported);
    }

    /**
     * @return list<string>
     */
    private function unthrottledAuthenticatedApiRoutes(): array
    {
        $unthrottled = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $isAuthenticated = $this->parametersOf($route, Authenticate::class) !== [];

            $hasThrottle = $this->parametersOf($route, ThrottleRequests::class) !== []
                || $this->parametersOf($route, ThrottleAfterAccountResolution::class) !== [];

            if ($isAuthenticated && ! $hasThrottle) {
                $unthrottled[] = $route->uri();
            }
        }

        return $unthrottled;
    }

    /**
     * Every named limiter a route attaches is registered.
     *
     * "Carries a throttle" above is satisfied by a name nobody registered, and
     * Laravel does not check the name until a request arrives: ThrottleRequests
     * then throws MissingRateLimiterException and the route answers 500 for
     * everyone. That fails closed, but on the one road the limiter protects,
     * in production, and only when somebody uses it — which for an invitation
     * or a password reset can be days after the deploy. Asked of the whole
     * route table here, the mistake is a red test instead.
     *
     * Numeric throttles (`throttle:N,M,prefix` and `throttle:N|M`) name no
     * limiter and are OneThrottleBucketPerVerbTest's business.
     */
    #[Test]
    public function every_limiter_a_route_names_is_registered(): void
    {
        $router = app(Router::class);
        $unregistered = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($router->gatherRouteMiddleware($route) as $middleware) {
                if (! is_string($middleware) || ! str_contains($middleware, ':')) {
                    continue;
                }

                [$class, $parameters] = explode(':', $middleware, 2);

                if (! is_a($class, ThrottleRequests::class, true) && $class !== ThrottleAfterAccountResolution::class) {
                    continue;
                }

                $name = explode(',', $parameters)[0];

                if (array_filter(explode('|', $name), static fn (string $part): bool => ! is_numeric($part)) === []) {
                    continue;
                }

                if (RateLimiter::limiter($name) === null) {
                    $unregistered[] = sprintf('%s %s names [%s]', $route->methods()[0], $route->uri(), $name);
                }
            }
        }

        $this->assertSame([], $unregistered, implode("\n", [
            'These routes name a rate limiter nobody registered; each answers 500 on its first request:',
            ...$unregistered,
        ]));
    }

    #[Test]
    public function the_throttle_runs_after_authentication_so_the_per_user_limiter_applies(): void
    {
        // The limiter keys on the acting user and honours a token's own
        // ceiling, both of which need a resolved user. Laravel's middleware
        // priority must therefore place Authenticate before ThrottleRequests.
        $route = Route::getRoutes()->getByName('api.v1.me');
        $this->assertNotNull($route);

        // What runs, in the order it runs: the router's own sort into the
        // kernel's middleware priority, with exclusions removed.
        $authIndex = null;
        $throttleIndex = null;

        foreach ($this->middlewareTheRouteRuns($route) as $index => $entry) {
            if ($entry === Authenticate::class.':sanctum') {
                $authIndex = $index;
            }

            if (is_a(explode(':', $entry, 2)[0], ThrottleRequests::class, true)) {
                $throttleIndex = $index;
            }
        }

        $this->assertNotNull($authIndex);
        $this->assertNotNull($throttleIndex);
        $this->assertLessThan($throttleIndex, $authIndex);
    }

    #[Test]
    public function repeated_wrong_current_password_on_two_factor_disable_locks_the_account(): void
    {
        $user = User::factory()->withTwoFactor()->create([
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);

        // The attacker already holds the session; they do not know the password.
        $this->actingAs($user);

        for ($i = 0; $i < User::MAX_FAILED_LOGIN_ATTEMPTS; $i++) {
            $this->deleteJson(route('api.v1.me.2fa.disable'), [
                'current_password' => 'guess-'.$i,
            ])->assertStatus(422);
        }

        $user->refresh();
        $this->assertTrue($user->isLocked());

        // And once locked, even the correct password no longer removes the
        // second factor: the oracle is closed rather than merely slowed.
        $this->deleteJson(route('api.v1.me.2fa.disable'), [
            'current_password' => self::PASSWORD,
        ])->assertStatus(422);

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function repeated_wrong_current_password_on_the_password_change_locks_the_account(): void
    {
        $user = User::factory()->create([
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($user);

        for ($i = 0; $i < User::MAX_FAILED_LOGIN_ATTEMPTS; $i++) {
            $this->putJson(route('api.v1.me.password'), [
                'current_password' => 'guess-'.$i,
                'password' => 'a-brand-new-secret-1',
                'password_confirmation' => 'a-brand-new-secret-1',
            ])->assertStatus(422);
        }

        $this->assertTrue($user->fresh()->isLocked());
    }

    #[Test]
    public function an_occasional_mistake_never_accumulates_into_a_lockout(): void
    {
        $user = User::factory()->create([
            'email' => 'amal@example.com',
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->actingAs($user);

        for ($round = 0; $round < 3; $round++) {
            $this->postJson(route('api.v1.me.2fa.enable'), ['current_password' => 'wrong'])
                ->assertStatus(422);

            $this->postJson(route('api.v1.me.2fa.enable'), ['current_password' => self::PASSWORD])
                ->assertOk();
        }

        $user->refresh();
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertFalse($user->isLocked());
    }
}
