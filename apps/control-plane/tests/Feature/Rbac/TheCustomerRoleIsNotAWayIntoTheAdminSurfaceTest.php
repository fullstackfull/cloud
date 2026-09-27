<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Lynomia\Http\Middleware\EnsureTheCallerIsStaff;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The baseline customer role is not a way into /api/admin (OB-1, re-audit of
 * round three, F-03 class).
 *
 * Measured at 88c4dd8: a delegate holding support + role.manage + catalog.view
 * — a configuration SetRolePermissions' own docblock supports — sent
 * PUT /api/admin/roles/customer/permissions with its own permissions and got
 * 200. From then on every customer login, including one registered a minute
 * later, read GET /api/admin/operators and GET /api/admin/customers: 200. A
 * super admin was not refused either. The operator-roles route already refused
 * to hand out `customer` for exactly this reason; the permissions route was
 * the other door into the same room.
 *
 * Two layers, each tested on its own:
 *
 *  1. The customer role's permission list is fixed by the platform. Nobody —
 *     delegate or super admin — edits it through the operator surface, and
 *     the role catalogue no longer advertises it as editable.
 *  2. The /api/admin surface refuses a login that holds no staff role, before
 *     any permission is consulted, so a customer role that somehow held an
 *     operator permission (a seeder edit, a SQL client) still opens nothing.
 */
final class TheCustomerRoleIsNotAWayIntoTheAdminSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * The verifier's probe, verbatim.
     */
    #[Test]
    public function a_delegate_cannot_give_the_customer_role_operator_permissions(): void
    {
        $delegate = User::factory()->create();
        $delegate->syncRoles([Role::Support->value]);
        $delegate->givePermissionTo(Permission::RoleManage->value, Permission::CatalogView->value);

        $before = $this->customerRolePermissions();

        $this->actingAs($delegate)
            ->putJson('/api/admin/roles/'.Role::Customer->value.'/permissions', [
                'permissions' => $delegate->getAllPermissions()->pluck('name')->values()->all(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.role_is_the_customer_baseline');

        $this->assertSame($before, $this->customerRolePermissions(), 'The customer role was widened by a delegate.');
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::RolePermissionsChanged)->count());

        $customer = $this->customerLogin();

        $this->actingAs($customer)->getJson('/api/admin/operators')->assertForbidden();
        $this->actingAs($customer)->getJson('/api/admin/customers')->assertForbidden();
    }

    #[Test]
    public function a_super_admin_cannot_edit_the_customer_role_either(): void
    {
        $admin = User::factory()->create();
        $admin->syncRoles([Role::SuperAdmin->value]);

        $before = $this->customerRolePermissions();

        foreach ([[Permission::CustomerViewAny->value, Permission::CatalogView->value], []] as $permissions) {
            $this->actingAs($admin)
                ->putJson('/api/admin/roles/'.Role::Customer->value.'/permissions', ['permissions' => $permissions])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'rbac.role_is_the_customer_baseline');
        }

        $this->assertSame($before, $this->customerRolePermissions());
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::RolePermissionsChanged)->count());

        // Positive control: the same super admin still edits a staff role.
        $this->actingAs($admin)
            ->putJson('/api/admin/roles/'.Role::Noc->value.'/permissions', ['permissions' => [Permission::MonitoringView->value]])
            ->assertOk();
    }

    #[Test]
    public function the_role_catalogue_does_not_offer_the_customer_role_as_editable(): void
    {
        $admin = User::factory()->create();
        $admin->syncRoles([Role::SuperAdmin->value]);

        $roles = collect((array) $this->actingAs($admin)->getJson('/api/admin/roles')->assertOk()->json('data'));

        $this->assertFalse($roles->firstWhere('name', Role::Customer->value)['permissions_are_editable'] ?? true);
        $this->assertTrue($roles->firstWhere('name', Role::Noc->value)['permissions_are_editable'] ?? false);

        $this->actingAs($admin)
            ->getJson('/api/admin/roles/'.Role::Customer->value)
            ->assertOk()
            ->assertJsonPath('data.permissions_are_editable', false);
    }

    /**
     * Defence in depth: even if the customer role DID hold operator
     * permissions — written straight into the table, not through the route
     * above — a customer login is refused by the staff gate.
     */
    #[Test]
    public function a_customer_login_is_refused_even_when_the_customer_role_holds_operator_permissions(): void
    {
        SpatieRole::query()->where('name', Role::Customer->value)->firstOrFail()
            ->givePermissionTo(Permission::CustomerViewAny->value, Permission::RoleManage->value, Permission::TicketViewAny->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $customer = $this->customerLogin();
        $this->assertTrue($customer->can(Permission::CustomerViewAny->value), 'Precondition: the permission is held.');

        foreach (['/api/admin/customers', '/api/admin/operators', '/api/admin/roles', '/api/admin/support/tickets'] as $uri) {
            $this->actingAs($customer)
                ->getJson($uri)
                ->assertForbidden()
                ->assertJsonPath('error.code', 'auth.forbidden');
        }
    }

    /**
     * A permission given straight to a login that holds no role at all is not
     * operator authority either.
     */
    #[Test]
    public function a_login_with_a_direct_permission_and_no_staff_role_is_refused(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::CustomerViewAny->value);

        $this->actingAs($user)->getJson('/api/admin/customers')->assertForbidden();

        // Positive control: the same permission through a staff role opens it.
        $user->assignRole(Role::Support->value);

        $this->actingAs($user->fresh())->getJson('/api/admin/customers')->assertOk();
    }

    #[Test]
    public function every_staff_role_passes_the_gate(): void
    {
        foreach (Role::cases() as $role) {
            if (! $role->isStaffRole()) {
                continue;
            }

            $user = User::factory()->create();
            $user->syncRoles([$role->value]);
            $user->givePermissionTo(Permission::AuditView->value);

            $this->actingAs($user)->getJson('/api/admin/audit')->assertOk();
        }
    }

    /**
     * Reads, for every route whose URI starts `api/admin`, the list
     * Router::gatherRouteMiddleware() returns: the middleware the router runs
     * for that route — group and route middleware resolved to class names
     * (with parameters), anything named by `withoutMiddleware()` removed, and
     * the result sorted into the kernel's middleware priority. It asserts that
     * list holds `Authenticate:sanctum` and EnsureTheCallerIsStaff, the staff
     * gate after the authentication.
     *
     * Not Route::gatherMiddleware(): that is what the route declares, and it
     * keeps `staff` in the list when `->withoutMiddleware('staff')` removes it
     * from what runs (OB5-2, re-audit of round four — that mutation on
     * audit.index left this test green while a customer whose role held
     * audit.view read GET /api/admin/audit: 200).
     *
     * It reads route registration, not requests; the behaviour of the gate is
     * held by the tests above.
     */
    #[Test]
    public function every_admin_route_carries_the_staff_gate_after_authentication(): void
    {
        $router = app(Router::class);
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin')) {
                continue;
            }

            $middleware = array_values(array_filter($router->gatherRouteMiddleware($route), 'is_string'));
            $auth = array_search(Authenticate::class.':sanctum', $middleware, true);
            $staff = array_search(EnsureTheCallerIsStaff::class, $middleware, true);

            if ($auth === false || $staff === false || $staff < $auth) {
                $missing[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }

        $this->assertSame([], $missing, "Admin routes that do not run the staff gate after auth:sanctum:\n  ".implode("\n  ", $missing));
    }

    /**
     * @return list<string>
     */
    private function customerRolePermissions(): array
    {
        return SpatieRole::query()->where('name', Role::Customer->value)->firstOrFail()
            ->permissions()->pluck('name')->sort()->values()->all();
    }

    private function customerLogin(): User
    {
        $customer = User::factory()->create();
        $customer->syncRoles([Role::Customer->value]);

        return $customer;
    }
}
