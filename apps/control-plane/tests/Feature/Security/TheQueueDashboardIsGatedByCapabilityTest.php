<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Closure;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Http\Middleware\Authenticate as HorizonGate;
use Laravel\Horizon\Jobs\RetryFailedJob;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Application\Actions\InviteOperator;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The queue dashboard is authorised by capability, never by environment name.
 *
 * ---------------------------------------------------------------------------
 * What was there
 * ---------------------------------------------------------------------------
 *
 * Nothing called `Horizon::auth()`, so `Horizon::check()` fell back to the
 * package default — `app()->environment('local')` — and that one string
 * comparison was the whole gate on 22 routes (18 read, 3 POST, 1 DELETE),
 * four of which change what the queue does. It failed closed in production,
 * so the defect was never "open in production": it was that authorisation was
 * delegated to the value of `APP_ENV`. Any host labelled `local` served every
 * job payload and every failed-job stack trace to a visitor nobody had signed
 * in; a signed-in operator with no queue permission got the same access as one
 * with every permission; and staging, which `config/horizon.php` namespaces
 * precisely so it can coexist with production, was locked out entirely.
 *
 * `environment('local')` is also the same exact-string instrument as the
 * miscapitalised `APP_ENV=Production` that disarmed every production guard at
 * once (AMiscapitalisedEnvironmentCannotDisarmTheProductionGuardsTest). A fix
 * that tested the environment in a different costume would have reproduced
 * the class, so these tests rebind the environment under a live request and
 * demand the same answer in every one of them.
 *
 * ---------------------------------------------------------------------------
 * Why a refusal test is not enough on its own
 * ---------------------------------------------------------------------------
 *
 * The suite runs in `testing`, where the package default refuses everybody.
 * So `an_authenticated_non_operator_is_refused` is green against no gate at
 * all: 403-for-everyone is exactly what an absence looks like from outside.
 * That is why `the_auth_callback_is_registered_at_all` exists — the defect is
 * an absence, and absence comes back silently — and why the environment is
 * rebound rather than trusted in the tests that measure behaviour.
 *
 * ---------------------------------------------------------------------------
 * What these pin, and the direction each one faces
 * ---------------------------------------------------------------------------
 *
 * Capabilities are pinned by identity, not only by role: a user holding the
 * one permission alone is admitted, and a user holding every permission but
 * that one is refused. Pinning roles alone would let a later narrowing, or an
 * accidental widening, go green — the seeded roles hold many permissions and
 * several of them satisfy a role-based assertion.
 *
 * The gate's own routes are read from the live router, never from a file:
 * the name→verb map fails if Horizon adds, removes or re-verbs a route, and
 * the middleware check reads the stack the framework resolved rather than
 * inferring it from a controller's parent class.
 */
final class TheQueueDashboardIsGatedByCapabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The 22 routes the gate was written for, exactly as the router registers
     * them. HEAD is the framework's addition to every GET and is left out.
     *
     * @var array<string, string>
     */
    private const array ROUTES = [
        'horizon.stats.index' => 'GET',
        'horizon.workload.index' => 'GET',
        'horizon.masters.index' => 'GET',
        'horizon.monitoring.index' => 'GET',
        'horizon.monitoring.store' => 'POST',
        'horizon.monitoring-tag.paginate' => 'GET',
        'horizon.monitoring-tag.destroy' => 'DELETE',
        'horizon.jobs-metrics.index' => 'GET',
        'horizon.jobs-metrics.show' => 'GET',
        'horizon.queues-metrics.index' => 'GET',
        'horizon.queues-metrics.show' => 'GET',
        'horizon.jobs-batches.index' => 'GET',
        'horizon.jobs-batches.show' => 'GET',
        'horizon.jobs-batches.retry' => 'POST',
        'horizon.pending-jobs.index' => 'GET',
        'horizon.completed-jobs.index' => 'GET',
        'horizon.silenced-jobs.index' => 'GET',
        'horizon.failed-jobs.index' => 'GET',
        'horizon.failed-jobs.show' => 'GET',
        'horizon.retry-jobs.show' => 'POST',
        'horizon.jobs.show' => 'GET',
        'horizon.index' => 'GET',
    ];

    /**
     * Every environment name the gate might be asked under. `Local` is here
     * because an exact-string gate treats it as a different environment.
     *
     * @var list<string>
     */
    private const array ENVIRONMENTS = ['local', 'Local', 'staging', 'production', 'testing'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function the_auth_callback_is_registered_at_all(): void
    {
        /*
         * Horizon keeps the callback in a static, which outlives the
         * application that set it. Clearing it and booting a fresh application
         * is the only way to ask whether *this* boot registers one — otherwise
         * a callback left behind by an earlier test answers for a provider that
         * no longer registers anything.
         */
        Horizon::$authUsing = null;
        $this->refreshApplication();

        $this->assertInstanceOf(
            Closure::class,
            Horizon::$authUsing,
            'Booting the application registered no Horizon::auth() callback, so the dashboard is gated by APP_ENV again.',
        );

        // And it is not the package default in a different place: under the
        // one environment the default admits everybody, nobody signed in is
        // still refused.
        $this->app->instance('env', 'local');
        $this->getJson('/horizon/api/masters')->assertForbidden();
    }

    #[Test]
    public function an_unauthenticated_visitor_is_refused_in_every_environment(): void
    {
        foreach (self::ENVIRONMENTS as $environment) {
            $this->app->instance('env', $environment);

            $this->expectStatus(403, $this->getJson('/horizon/api/masters'), "unauthenticated, APP_ENV={$environment}");
            $this->expectStatus(403, $this->get('/horizon'), "unauthenticated dashboard shell, APP_ENV={$environment}");
        }
    }

    #[Test]
    public function the_gate_never_consults_the_environment_name(): void
    {
        $customer = $this->userWithRole(Role::Customer);
        $reader = $this->userWithRole(Role::Support);

        foreach (self::ENVIRONMENTS as $environment) {
            $this->app->instance('env', $environment);

            $this->expectStatus(403, $this->actingAs($customer)->getJson('/horizon/api/masters'), "a customer, APP_ENV={$environment}");
            $this->expectStatus(200, $this->actingAs($reader)->getJson('/horizon/api/masters'), "an operator holding provisioning.view, APP_ENV={$environment}");
        }
    }

    #[Test]
    public function an_authenticated_non_operator_is_refused(): void
    {
        // Green against no gate at all in `testing` — see the class docblock.
        Bus::fake();
        $customer = $this->userWithRole(Role::Customer);

        $this->actingAs($customer)->get('/horizon')->assertForbidden();
        $this->actingAs($customer)->getJson('/horizon/api/jobs/failed')->assertForbidden();
        $this->actingAs($customer)->postJson('/horizon/api/jobs/retry/f30-probe')->assertForbidden();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function a_signed_in_principal_that_is_not_a_platform_user_is_refused(): void
    {
        // One guard and one Authenticatable today, so this is unreachable
        // today; it pins the direction the closure fails in if that changes.
        $this->actingAs(new GenericUser(['id' => 1]))
            ->getJson('/horizon/api/masters')
            ->assertForbidden();
    }

    #[Test]
    public function an_operator_who_holds_the_read_capability_can_read_the_dashboard(): void
    {
        $reader = $this->userWithRole(Role::Support);

        $this->actingAs($reader)->get('/horizon')->assertOk();
        $this->actingAs($reader)->getJson('/horizon/api/jobs/failed')->assertOk();
        // HEAD is a read: it is classified with GET, not with the writes.
        $this->actingAs($reader)->call('HEAD', '/horizon/api/masters')->assertOk();
    }

    #[Test]
    public function reading_needs_provisioning_view_itself_and_nothing_else_will_do(): void
    {
        $onlyTheCapability = $this->userWithPermissions([Permission::ProvisioningView]);
        $everythingElse = $this->userWithPermissions($this->allPermissionsExcept(Permission::ProvisioningView));

        $this->actingAs($onlyTheCapability)->getJson('/horizon/api/jobs/failed')->assertOk();
        $this->actingAs($everythingElse)->getJson('/horizon/api/jobs/failed')->assertForbidden();
    }

    #[Test]
    public function a_reader_is_refused_every_one_of_the_writes(): void
    {
        Bus::fake();
        $reader = $this->userWithRole(Role::Support);

        $writes = $this->writeRoutes();
        $this->assertCount(4, $writes);

        foreach ($writes as $route) {
            $this->expectStatus(403, $this->send($reader, $route), 'provisioning.view alone, '.$this->describe($route));
        }

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function an_operator_who_holds_the_write_capability_reaches_every_write(): void
    {
        Bus::fake();
        $writer = $this->userWithRole(Role::Noc);

        foreach ($this->writeRoutes() as $route) {
            $this->expectStatus(200, $this->send($writer, $route), 'NOC, '.$this->describe($route));
        }
    }

    #[Test]
    public function writing_needs_provisioning_retry_itself_and_nothing_else_will_do(): void
    {
        Bus::fake();
        $onlyTheCapability = $this->userWithPermissions([Permission::ProvisioningRetry]);
        $everythingElse = $this->userWithPermissions($this->allPermissionsExcept(Permission::ProvisioningRetry));

        foreach ($this->writeRoutes() as $route) {
            $this->expectStatus(200, $this->send($onlyTheCapability, $route), 'provisioning.retry alone, '.$this->describe($route));
            $this->expectStatus(403, $this->send($everythingElse, $route), 'every permission but provisioning.retry, '.$this->describe($route));
        }
    }

    #[Test]
    public function the_super_admin_reaches_it_through_the_platform_bypass(): void
    {
        Bus::fake();
        // Seeded with no permissions at all; only the Gate::before admits it.
        $superAdmin = $this->userWithRole(Role::SuperAdmin);

        $this->actingAs($superAdmin)->getJson('/horizon/api/jobs/failed')->assertOk();
        $this->actingAs($superAdmin)->postJson('/horizon/api/jobs/retry/f30-probe')->assertOk();
    }

    #[Test]
    public function a_network_engineer_is_not_handed_serialised_payloads(): void
    {
        /*
         * Network Engineer holds monitoring.view and nothing commercial — not
         * even customer.view_any. The failed-job view renders serialised
         * payment payloads, so the read capability is the one that already
         * answers "may this person read provisioning jobs" on the admin API.
         */
        $engineer = $this->userWithRole(Role::NetworkEngineer);
        $this->assertTrue($engineer->can(Permission::MonitoringView->value));

        $this->actingAs($engineer)->getJson('/horizon/api/jobs/failed')->assertForbidden();
        $this->actingAs($engineer)->get('/horizon')->assertForbidden();
    }

    #[Test]
    public function an_operator_whose_address_was_never_verified_is_refused(): void
    {
        Bus::fake();
        Notification::fake();

        /*
         * The platform's own operator-creation path. An existing login is
         * promoted rather than refused, and `email_verified_at` is only set
         * when the row is new — so a self-registered, never-verified customer
         * promoted to NOC and Support keeps it null.
         */
        $customer = User::factory()->unverified()->create(['email' => 'promoted@example.com']);
        $customer->syncRoles([Role::Customer->value]);

        $operator = $this->app->make(InviteOperator::class)->execute(
            $this->userWithRole(Role::SuperAdmin),
            'promoted@example.com',
            'Promoted Operator',
            [Role::Noc->value, Role::Support->value],
        );

        $this->assertTrue($operator->is($customer));
        $this->assertNull($operator->email_verified_at);
        $this->assertTrue($operator->can(Permission::ProvisioningView->value));
        $this->assertTrue($operator->can(Permission::ProvisioningRetry->value));

        // The admin API already refuses this account through `verified`…
        $this->actingAs($operator)->getJson('/api/admin/provisioning/jobs')->assertForbidden();

        // …so the dashboard, which shows the same jobs with their payloads,
        // refuses it too.
        $this->actingAs($operator)->get('/horizon')->assertForbidden();
        $this->actingAs($operator)->getJson('/horizon/api/jobs/failed')->assertForbidden();
        foreach ($this->writeRoutes() as $route) {
            $this->expectStatus(403, $this->send($operator, $route), 'unverified NOC, '.$this->describe($route));
        }
        Bus::assertNothingDispatched();

        // Verifying the address is all it takes.
        $operator->markEmailAsVerified();
        $this->actingAs($operator->fresh() ?? $operator)->getJson('/horizon/api/jobs/failed')->assertOk();
    }

    #[Test]
    public function the_twenty_two_routes_are_the_ones_the_gate_was_written_for(): void
    {
        /*
         * The closure classifies by HTTP verb, so a Horizon release that adds
         * a route, or turns a read into a write, changes what the gate means.
         * Read from the live router so that such a change fails here rather
         * than being classified silently.
         */
        $registered = [];

        foreach ($this->horizonRoutes() as $route) {
            $registered[(string) $route->getName()] = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));
        }

        ksort($registered);
        $expected = self::ROUTES;
        ksort($expected);

        $this->assertSame($expected, $registered);
    }

    #[Test]
    public function every_horizon_route_carries_the_gate(): void
    {
        /*
         * Asked of the router, not inferred: `gatherRouteMiddleware()` is the
         * stack the framework will actually run, controller middleware
         * included. Inferring it from the controller's parent class would not
         * notice a subclass that withdrew the middleware.
         */
        $router = $this->app->make(Router::class);
        $ungated = [];
        $routes = $this->horizonRoutes();

        foreach ($routes as $route) {
            if (! in_array(HorizonGate::class, $router->gatherRouteMiddleware($route), true)) {
                $ungated[] = $this->describe($route);
            }
        }

        $this->assertCount(22, $routes);
        $this->assertSame([], $ungated, "Horizon routes the gate never runs on:\n  ".implode("\n  ", $ungated));
    }

    #[Test]
    public function the_runbook_table_is_measured_rather_than_read_off_middleware_order(): void
    {
        /*
         * Laravel skips CSRF while `runningUnitTests()`, which is
         * `$app['env'] === 'testing'`. Rebinding the environment switches the
         * check back on, so the answers the runbook gives an operator are
         * measured here rather than inferred from the middleware order.
         */
        Bus::fake();
        $this->app->instance('env', 'local');
        $token = 'f30-csrf-token';

        // Nobody signed in: a read reaches the gate; a write is refused by
        // CSRF before the gate runs.
        $this->getJson('/horizon/api/masters')->assertForbidden();
        $this->postJson('/horizon/api/jobs/retry/f30-probe')->assertStatus(419);
        $this->deleteJson('/horizon/api/monitoring/f30-probe')->assertStatus(419);

        // Signed in with the write capability: the token decides.
        $writer = $this->userWithRole(Role::Noc);
        $this->actingAs($writer)->postJson('/horizon/api/jobs/retry/f30-probe')->assertStatus(419);
        $this->actingAs($writer)
            ->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/horizon/api/jobs/retry/f30-probe')
            ->assertOk();

        // Signed in, token valid, capability missing: the gate decides.
        $this->actingAs($this->userWithRole(Role::Support))
            ->withSession(['_token' => $token])
            ->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('/horizon/api/jobs/retry/f30-probe')
            ->assertForbidden();

        // Signed in with both capabilities, address never verified.
        $unverified = User::factory()->unverified()->create();
        $unverified->syncRoles([Role::Noc->value]);
        $this->actingAs($unverified)->getJson('/horizon/api/masters')->assertForbidden();

        // OPTIONS is answered by the router itself and never reaches the gate.
        $options = $this->call('OPTIONS', '/horizon/api/jobs/retry/f30-probe');
        $options->assertOk();
        $this->assertStringContainsString('POST', (string) $options->headers->get('Allow'));

        Bus::assertDispatchedTimes(RetryFailedJob::class, 1);
    }

    private function userWithRole(Role $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    /**
     * A user holding exactly these permissions, granted directly and through
     * no role, so the assertion is about the capability and not the grouping.
     *
     * @param  list<Permission>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(static fn (Permission $p): string => $p->value, $permissions));

        return $user;
    }

    /**
     * @return list<Permission>
     */
    private function allPermissionsExcept(Permission $withheld): array
    {
        return array_values(array_filter(
            Permission::cases(),
            static fn (Permission $p): bool => $p !== $withheld,
        ));
    }

    /**
     * @return list<RoutingRoute>
     */
    private function horizonRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'horizon.'),
        ));
    }

    /**
     * The routes that change what the queue does, found by verb from the live
     * router — the same axis the gate classifies on.
     *
     * @return list<RoutingRoute>
     */
    private function writeRoutes(): array
    {
        return array_values(array_filter(
            $this->horizonRoutes(),
            static fn (RoutingRoute $route): bool => array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [],
        ));
    }

    /**
     * @return TestResponse<Response>
     */
    private function send(User $user, RoutingRoute $route): TestResponse
    {
        $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
        $uri = '/'.preg_replace('/\{[^}]+\}/', 'f30-probe', $route->uri());

        return $this->actingAs($user)->json($method, $uri, ['tag' => 'f30-probe']);
    }

    private function describe(RoutingRoute $route): string
    {
        return implode('|', $route->methods()).' /'.$route->uri().' ('.$route->getName().')';
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function expectStatus(int $expected, TestResponse $response, string $context): void
    {
        $this->assertSame($expected, $response->getStatusCode(), $context);
    }
}
