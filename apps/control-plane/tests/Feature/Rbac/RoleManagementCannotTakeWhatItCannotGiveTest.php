<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Application\Actions\ChangeOperatorRoles;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Rbac\Domain\Exceptions\RoleChangeRefusedException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

/**
 * Authority is neither given nor taken by somebody who does not hold it.
 *
 * The re-audit after round two found the grant side guarded and the removal
 * side open, each measured over HTTP:
 *
 *  - ChangeOperatorRoles checked only the roles in the new set. A support
 *    operator holding `role.manage` set a super admin's roles to [support]
 *    and got 200: the top authority demoted by a lesser one, stopped only
 *    when the target happened to be the last super admin.
 *  - SetRolePermissions checked only the permissions in the new set, then
 *    replaced the whole set. The same operator emptied `infrastructure-admin`,
 *    permissions they do not hold themselves: 200, remaining [].
 *  - InviteOperator committed the user row and its OperatorInvited audit entry
 *    before the role grant, so a refused grant (422) left both behind.
 *  - `operator:bootstrap` used firstOrNew by email, so on a deployment with no
 *    super admin it would promote an existing customer login with that address
 *    and replace its password.
 */
final class RoleManagementCannotTakeWhatItCannotGiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function a_delegate_cannot_strip_super_admin_even_when_another_remains(): void
    {
        $delegate = $this->delegate(Role::Support);
        $target = $this->operator(Role::SuperAdmin);
        $spare = $this->operator(Role::SuperAdmin);

        $this->actingAs($delegate)
            ->putJson('/api/admin/operators/'.$target->id.'/roles', ['roles' => [Role::Support->value]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.role_not_yours_to_remove');

        $this->assertTrue($target->fresh()?->hasRole(Role::SuperAdmin->value), 'The super admin was demoted by a support operator.');
        $this->assertTrue($spare->fresh()?->hasRole(Role::SuperAdmin->value));
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::OperatorRolesChanged)->count());
    }

    #[Test]
    public function a_delegate_cannot_remove_any_role_they_do_not_hold(): void
    {
        $delegate = $this->delegate(Role::Support);
        $target = $this->operator(Role::Noc);
        $target->assignRole(Role::Support->value);

        $this->actingAs($delegate)
            ->putJson('/api/admin/operators/'.$target->id.'/roles', ['roles' => [Role::Support->value]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.role_not_yours_to_remove');

        $this->assertTrue($target->fresh()?->hasRole(Role::Noc->value));
    }

    /**
     * The positive control: the delegate may still change an operator whose
     * staff roles, before and after, are all roles the delegate holds.
     */
    #[Test]
    public function a_delegate_may_still_move_roles_they_hold(): void
    {
        $delegate = $this->delegate(Role::Support);
        $delegate->assignRole(Role::Finance->value);
        $target = $this->operator(Role::Support);

        $this->actingAs($delegate)
            ->putJson('/api/admin/operators/'.$target->id.'/roles', ['roles' => [Role::Finance->value]])
            ->assertOk();

        $this->assertTrue($target->fresh()?->hasRole(Role::Finance->value));
        $this->assertFalse($target->fresh()?->hasRole(Role::Support->value));
    }

    /**
     * The last-administrator rule still stands behind the removal rule, for
     * the one road that reaches it: a super admin whose own authority was
     * taken away between the request's check and the locked read (two super
     * admins demoting each other at once). Simulated by removing the actor's
     * role in the database while the actor instance still carries it.
     */
    #[Test]
    public function the_last_administrator_rule_still_holds_under_the_lock(): void
    {
        $actor = $this->operator(Role::SuperAdmin);
        $target = $this->operator(Role::SuperAdmin);

        $actor->load('roles');
        DB::table('model_has_roles')
            ->where('model_id', $actor->id)
            ->where('role_id', SpatieRole::query()->where('name', Role::SuperAdmin->value)->firstOrFail()->id)
            ->delete();

        try {
            app(ChangeOperatorRoles::class)->execute($actor, $target, [Role::Noc->value]);
            $this->fail('The last super admin was stripped.');
        } catch (RoleChangeRefusedException $e) {
            $this->assertSame('rbac.last_administrator', $e->errorCode());
        }

        $this->assertTrue($target->fresh()?->hasRole(Role::SuperAdmin->value));
    }

    #[Test]
    public function a_delegate_cannot_empty_a_role_of_permissions_they_do_not_hold(): void
    {
        $delegate = $this->delegate(Role::Support);
        $role = SpatieRole::query()->where('name', Role::InfrastructureAdmin->value)->firstOrFail();
        $before = $role->permissions()->pluck('name')->sort()->values()->all();
        $this->assertNotEmpty($before);

        $this->actingAs($delegate)
            ->putJson('/api/admin/roles/'.Role::InfrastructureAdmin->value.'/permissions', ['permissions' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.permission_not_yours_to_remove');

        $this->assertSame($before, $role->fresh()?->permissions()->pluck('name')->sort()->values()->all());
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::RolePermissionsChanged)->count());
    }

    /**
     * The positive control: a permission the delegate holds can be taken off
     * a role whose other permissions the delegate also holds.
     */
    #[Test]
    public function a_delegate_may_still_remove_a_permission_they_hold(): void
    {
        $delegate = $this->delegate(Role::Support);
        $support = array_map(static fn (Permission $p): string => $p->value, Role::Support->defaultPermissions());
        $this->assertGreaterThan(1, count($support));
        $kept = array_slice($support, 1);

        $this->actingAs($delegate)
            ->putJson('/api/admin/roles/'.Role::Finance->value.'/permissions', ['permissions' => []])
            ->assertStatus(422);

        $this->actingAs($this->operator(Role::SuperAdmin))
            ->putJson('/api/admin/roles/'.Role::Noc->value.'/permissions', ['permissions' => $support])
            ->assertOk();

        $this->actingAs($delegate)
            ->putJson('/api/admin/roles/'.Role::Noc->value.'/permissions', ['permissions' => $kept])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            $kept,
            SpatieRole::query()->where('name', Role::Noc->value)->firstOrFail()->permissions()->pluck('name')->all(),
        );
    }

    #[Test]
    public function a_refused_invitation_leaves_no_user_and_no_audit_entry(): void
    {
        Notification::fake();

        $delegate = $this->delegate(Role::Support);
        $users = User::query()->withTrashed()->count();

        $this->actingAs($delegate)
            ->postJson('/api/admin/operators', [
                'email' => 'd@lynomia.test',
                'name' => 'D',
                'roles' => [Role::InfrastructureAdmin->value],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'rbac.role_not_yours_to_grant');

        $this->assertSame($users, User::query()->withTrashed()->count(), 'A refused invitation left a user row behind.');
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::OperatorInvited)->count());
        Notification::assertNothingSent();

        // Positive control: the same delegate can invite with a role they hold.
        $this->actingAs($delegate)
            ->postJson('/api/admin/operators', [
                'email' => 'd@lynomia.test',
                'name' => 'D',
                'roles' => [Role::Support->value],
            ])
            ->assertCreated();

        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::OperatorInvited)->count());
    }

    #[Test]
    public function the_bootstrap_refuses_an_address_that_already_has_a_login(): void
    {
        Notification::fake();

        $customer = User::factory()->create(['email' => 'owner@lynomia.test', 'password' => Hash::make('their-own-secret')]);
        $customer->syncRoles([Role::Customer->value]);
        $hash = (string) $customer->fresh()?->password;

        $this->artisan('operator:bootstrap', ['email' => 'Owner@Lynomia.test'])->assertFailed();

        $customer = $customer->fresh();
        $this->assertFalse($customer?->hasRole(Role::SuperAdmin->value), 'A customer login was promoted to super admin.');
        $this->assertSame($hash, (string) $customer?->password, 'The customer\'s password was replaced.');
        $this->assertSame(0, User::query()->role(Role::SuperAdmin->value)->count());
        $this->assertSame(0, AuditEntry::query()->where('action', AuditAction::OperatorBootstrapped)->count());
        Notification::assertNothingSent();

        // A soft-deleted login still owns its address.
        $customer?->delete();
        $this->artisan('operator:bootstrap', ['email' => 'owner@lynomia.test'])->assertFailed();
        $this->assertSame(0, User::query()->role(Role::SuperAdmin->value)->count());

        // And a fresh address still works: the refusal is about the address.
        $this->artisan('operator:bootstrap', ['email' => 'ops@lynomia.test'])->assertSuccessful();
        $this->assertSame(1, User::query()->role(Role::SuperAdmin->value)->count());
    }

    private function operator(Role $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    private function delegate(Role $role): User
    {
        $user = $this->operator($role);
        $user->givePermissionTo(Permission::RoleManage->value);

        return $user;
    }
}
