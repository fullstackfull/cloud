<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: every authenticated API route was unthrottled, because the `api`
 * limiter defined in RateLimitServiceProvider was never attached to a route or
 * a group. Together with a bare Hash::check on the endpoints that re-confirm
 * the account password, that gave anyone holding a stolen session cookie or a
 * leaked token an unlimited, lockout-free password oracle on the endpoint that
 * turns the second factor off.
 */
final class EveryAuthenticatedRouteIsThrottledTest extends TestCase
{
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
        $unthrottled = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $isAuthenticated = (bool) array_filter(
                $middleware,
                static fn ($m): bool => is_string($m) && str_contains($m, 'auth:sanctum'),
            );

            $hasThrottle = (bool) array_filter(
                $middleware,
                static fn ($m): bool => is_string($m)
                    && (str_starts_with($m, 'throttle') || str_starts_with($m, ThrottleRequests::class)),
            );

            if ($isAuthenticated && ! $hasThrottle) {
                $unthrottled[] = $route->uri();
            }
        }

        $this->assertSame(
            [],
            $unthrottled,
            'Authenticated API routes reachable at unlimited rate: '.implode(', ', $unthrottled),
        );
    }

    #[Test]
    public function the_throttle_runs_after_authentication_so_the_per_user_limiter_applies(): void
    {
        // The limiter keys on the acting user and honours a token's own
        // ceiling, both of which need a resolved user. Laravel's middleware
        // priority must therefore place Authenticate before ThrottleRequests.
        $route = Route::getRoutes()->getByName('api.v1.me');
        $this->assertNotNull($route);

        $middleware = array_values(array_filter(
            $route->gatherMiddleware(),
            static fn ($m): bool => is_string($m)
                && (str_contains($m, 'auth:sanctum') || str_starts_with($m, 'throttle')),
        ));

        $sorted = app(Router::class)->resolveMiddleware($middleware);

        $authIndex = null;
        $throttleIndex = null;

        foreach ($sorted as $index => $entry) {
            if (str_contains($entry, 'Authenticate:sanctum')) {
                $authIndex = $index;
            }

            if (str_starts_with($entry, ThrottleRequests::class)) {
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
