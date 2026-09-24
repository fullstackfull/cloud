<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may operate the platform, decided inside the platform.
 *
 * ---------------------------------------------------------------------------
 * The dead end
 * ---------------------------------------------------------------------------
 *
 * `role.manage` was declared in the permission catalogue and referenced by
 * nothing: no route, no controller, no policy. Eleven permissions were held by
 * no role and reachable only through the super-admin bypass, and there was no
 * supported way to grant any of them to anybody. A deployment with one
 * operator could never have a second one, and an operator who needed
 * `readiness.declare` could only be given it with a SQL client.
 *
 * ---------------------------------------------------------------------------
 * What the invariants are for
 * ---------------------------------------------------------------------------
 *
 * A surface that manages roles is a surface that can hand out every power the
 * platform has, so the interesting tests here are the refusals: an operator
 * cannot promote themselves, cannot hand out authority they do not hold, and
 * cannot leave the deployment with nobody able to administer it — which is the
 * dead end above, recreated from the inside.
 */
final class OperatorAndRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function operator(Role $role = Role::SuperAdmin): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    // ---- the surface exists and is reachable ------------------------------

    #[Test]
    public function role_manage_has_a_reachable_route_for_a_privileged_operator(): void
    {
        $response = $this->actingAs($this->operator())->getJson('/api/admin/roles')->assertOk();

        $names = array_column((array) $response->json('data'), 'name');

        // Every role the platform ships with, and what each one may do.
        foreach (Role::cases() as $role) {
            $this->assertContains($role->value, $names);
        }

        $superAdmin = collect((array) $response->json('data'))->firstWhere('name', Role::SuperAdmin->value);

        $this->assertFalse(
            $superAdmin['permissions_are_editable'] ?? true,
            'Super Admin is a bypass, so its permission list is not the thing that grants it anything.',
        );
    }

    #[Test]
    public function the_permissions_no_role_holds_are_grantable_rather_than_dead(): void
    {
        /*
         * Eleven permissions were held by no role. That is defensible for a
         * bypass model — they are super-admin-only until somebody decides
         * otherwise — but only if somebody *can* decide otherwise.
         */
        $orphans = array_values(array_filter(
            Permission::cases(),
            static function (Permission $permission): bool {
                foreach (Role::cases() as $role) {
                    if (in_array($permission, $role->defaultPermissions(), true)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        $this->assertNotEmpty($orphans, 'This test is about the ownerless permissions; there are none.');

        $this->actingAs($this->operator())
            ->putJson('/api/admin/roles/'.Role::Noc->value.'/permissions', [
                'permissions' => array_map(
                    static fn (Permission $permission): string => $permission->value,
                    [...Role::Noc->defaultPermissions(), ...$orphans],
                ),
            ])
            ->assertOk();

        $noc = User::factory()->create();
        $noc->syncRoles([Role::Noc->value]);

        foreach ($orphans as $permission) {
            $this->assertTrue(
                $noc->fresh()?->can($permission->value),
                sprintf('%s is still held by nobody and grantable by nobody.', $permission->value),
            );
        }
    }

    #[Test]
    public function the_permission_catalogue_is_readable_so_an_operator_can_choose(): void
    {
        $response = $this->actingAs($this->operator())->getJson('/api/admin/permissions')->assertOk();

        $this->assertSame(
            count(Permission::cases()),
            count((array) $response->json('data')),
            'The chooser does not offer every permission the platform defines.',
        );
    }

    // ---- one operator is enough -------------------------------------------

    #[Test]
    public function the_first_operator_can_create_the_second_without_a_second_super_admin(): void
    {
        Notification::fake();

        $first = $this->operator();

        // Exactly the failure the Round-1 report describes: completing a
        // deployment required two Super Admins, and a deployment starts with
        // one.
        $this->assertSame(1, User::query()->role(Role::SuperAdmin->value)->count());

        $this->actingAs($first)
            ->postJson('/api/admin/operators', [
                'email' => 'second@lynomia.test',
                'name' => 'Second Operator',
                'roles' => [Role::Noc->value],
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'second@lynomia.test');

        /** @var User $second */
        $second = User::query()->where('email', 'second@lynomia.test')->sole();

        $this->assertTrue($second->hasRole(Role::Noc->value));
        $this->assertTrue($second->can(Permission::InfrastructureView->value));

        // No password was chosen for them either.
        $this->assertFalse(password_verify('password', (string) $second->password));
    }

    #[Test]
    public function an_operators_roles_can_be_changed_and_the_change_is_recorded(): void
    {
        $actor = $this->operator();
        $target = $this->operator(Role::Noc);

        $this->actingAs($actor)
            ->putJson('/api/admin/operators/'.$target->id.'/roles', [
                'roles' => [Role::Support->value, Role::Finance->value],
            ])
            ->assertOk();

        $target->refresh();

        $this->assertTrue($target->hasRole(Role::Support->value));
        $this->assertTrue($target->hasRole(Role::Finance->value));
        $this->assertFalse($target->hasRole(Role::Noc->value));

        $entry = AuditEntry::query()->where('action', AuditAction::OperatorRolesChanged)->sole();

        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame([Role::Noc->value], $entry->context['before'] ?? null);
        $this->assertEqualsCanonicalizing(
            [Role::Support->value, Role::Finance->value],
            $entry->context['after'] ?? [],
        );
    }

    #[Test]
    public function a_permission_change_to_a_role_is_recorded_with_both_sides(): void
    {
        $actor = $this->operator();

        $this->actingAs($actor)
            ->putJson('/api/admin/roles/'.Role::Support->value.'/permissions', [
                'permissions' => [Permission::CustomerView->value],
            ])
            ->assertOk();

        $entry = AuditEntry::query()->where('action', AuditAction::RolePermissionsChanged)->sole();

        $this->assertSame(Role::Support->value, $entry->context['role'] ?? null);
        $this->assertContains(Permission::TicketReply->value, $entry->context['before'] ?? []);
        $this->assertSame([Permission::CustomerView->value], $entry->context['after'] ?? null);
    }

    // ---- the refusals ------------------------------------------------------

    #[Test]
    public function an_operator_cannot_change_their_own_roles(): void
    {
        $actor = $this->operator();

        $this->actingAs($actor)
            ->putJson('/api/admin/operators/'.$actor->id.'/roles', [
                'roles' => [Role::Noc->value],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.self_management');

        $this->assertTrue($actor->fresh()?->hasRole(Role::SuperAdmin->value));
    }

    #[Test]
    public function an_operator_cannot_hand_out_authority_they_do_not_hold(): void
    {
        /*
         * The escalation that matters. `role.manage` is grantable, so an
         * operator who holds it is not necessarily a super admin — and must
         * not be able to make one, or to make themselves one by proxy through
         * an account they control.
         */
        $delegate = User::factory()->create();
        $delegate->syncRoles([Role::Support->value]);
        $delegate->givePermissionTo(Permission::RoleManage->value);

        $target = $this->operator(Role::Noc);

        $this->actingAs($delegate)
            ->putJson('/api/admin/operators/'.$target->id.'/roles', [
                'roles' => [Role::SuperAdmin->value],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.role_not_yours_to_grant');

        $this->assertFalse($target->fresh()?->hasRole(Role::SuperAdmin->value));

        // And the same person may still do the part they are authorised for.
        $this->actingAs($delegate)
            ->putJson('/api/admin/operators/'.$target->id.'/roles', [
                'roles' => [Role::Support->value],
            ])
            ->assertOk();
    }

    #[Test]
    public function the_super_admin_permission_list_is_not_editable(): void
    {
        $this->actingAs($this->operator())
            ->putJson('/api/admin/roles/'.Role::SuperAdmin->value.'/permissions', [
                'permissions' => [Permission::CatalogView->value],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.role_is_protected');
    }

    #[Test]
    public function the_last_privileged_operator_cannot_be_stripped(): void
    {
        /*
         * Escalation by demotion, which is the shape this rule is actually
         * for. A delegate who holds `role.manage` cannot *grant* super admin —
         * the rule above stops that — but taking it away is not granting
         * anything, and a deployment with no administrator cannot get one
         * back: the console bootstrap refuses the moment one exists, and every
         * other path is behind a permission nobody would hold.
         */
        $delegate = User::factory()->create();
        $delegate->syncRoles([Role::Noc->value]);
        $delegate->givePermissionTo(Permission::RoleManage->value);

        $only = $this->operator();

        $this->assertSame(1, User::query()->role(Role::SuperAdmin->value)->count());

        $this->actingAs($delegate)
            ->putJson('/api/admin/operators/'.$only->id.'/roles', ['roles' => [Role::Noc->value]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.last_administrator');

        $this->assertTrue($only->fresh()?->hasRole(Role::SuperAdmin->value));
        $this->assertSame(1, User::query()->role(Role::SuperAdmin->value)->count());

        /*
         * The positive control: the same request succeeds the moment somebody
         * else can administer the platform, so the refusal above is the
         * last-admin rule and not a blanket "you may not touch a super admin".
         */
        $spare = $this->operator();

        $this->actingAs($delegate)
            ->putJson('/api/admin/operators/'.$only->id.'/roles', ['roles' => [Role::Noc->value]])
            ->assertOk();

        $this->assertFalse($only->fresh()?->hasRole(Role::SuperAdmin->value));
        $this->assertTrue($spare->fresh()?->hasRole(Role::SuperAdmin->value));
    }

    // ---- who may reach any of this ----------------------------------------

    #[Test]
    public function an_operator_without_the_permission_is_refused_at_every_verb(): void
    {
        $noc = $this->operator(Role::Noc);
        $target = $this->operator(Role::Support);

        $this->actingAs($noc)->getJson('/api/admin/roles')->assertForbidden();
        $this->actingAs($noc)->getJson('/api/admin/operators')->assertForbidden();
        $this->actingAs($noc)->getJson('/api/admin/permissions')->assertForbidden();
        $this->actingAs($noc)
            ->postJson('/api/admin/operators', ['email' => 'x@lynomia.test', 'name' => 'X', 'roles' => []])
            ->assertForbidden();
        $this->actingAs($noc)
            ->putJson('/api/admin/operators/'.$target->id.'/roles', ['roles' => [Role::Noc->value]])
            ->assertForbidden();
        $this->actingAs($noc)
            ->putJson('/api/admin/roles/'.Role::Support->value.'/permissions', ['permissions' => []])
            ->assertForbidden();

        // The positive control: the routes exist and were reached. A 404 would
        // have proved nothing about authorisation.
        $this->assertTrue($target->fresh()?->hasRole(Role::Support->value));
        $this->actingAs($this->operator())->getJson('/api/admin/roles')->assertOk();
    }

    #[Test]
    public function a_customer_cannot_reach_the_operator_surface(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $customer->members()->create(['user_id' => $user->id, 'role' => 'owner', 'accepted_at' => now()]);
        $user->syncRoles([Role::Customer->value]);

        $this->actingAs($user)->getJson('/api/admin/roles')->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/operators')->assertForbidden();
        $this->actingAs($user)
            ->putJson('/api/admin/roles/'.Role::SuperAdmin->value.'/permissions', ['permissions' => []])
            ->assertForbidden();
    }

    #[Test]
    public function the_customer_role_is_not_an_operator_role(): void
    {
        $target = $this->operator(Role::Noc);

        $this->actingAs($this->operator())
            ->putJson('/api/admin/operators/'.$target->id.'/roles', ['roles' => [Role::Customer->value]])
            ->assertStatus(422);

        $this->assertTrue($target->fresh()?->hasRole(Role::Noc->value));
    }
}
