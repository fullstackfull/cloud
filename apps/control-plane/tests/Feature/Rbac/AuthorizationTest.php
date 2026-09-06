<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\TestCase;

final class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function every_enumerated_permission_exists_in_the_database(): void
    {
        $seeded = PermissionModel::query()->pluck('name')->all();

        foreach (Permission::cases() as $permission) {
            $this->assertContains($permission->value, $seeded, "Permission {$permission->value} was not seeded.");
        }
    }

    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        $before = PermissionModel::query()->count();
        $rolesBefore = RoleModel::query()->count();

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($before, PermissionModel::query()->count());
        $this->assertSame($rolesBefore, RoleModel::query()->count());
    }

    #[Test]
    public function a_super_admin_holds_every_permission_without_them_being_enumerated(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole(Role::SuperAdmin->value);

        // The role has no explicit permissions attached, on purpose.
        $this->assertCount(0, RoleModel::findByName(Role::SuperAdmin->value)->permissions);

        // Yet it can do everything, including a permission added after the
        // role was seeded.
        foreach (Permission::cases() as $permission) {
            $this->assertTrue(
                Gate::forUser($superAdmin)->allows($permission->value),
                "Super Admin should be allowed {$permission->value}.",
            );
        }

        PermissionModel::findOrCreate('feature.added.later', 'web');
        $this->assertTrue(Gate::forUser($superAdmin)->allows('feature.added.later'));
    }

    #[Test]
    public function a_gate_before_rule_does_not_grant_other_users_everything(): void
    {
        // Guards against the classic bug where Gate::before returns false for
        // non-super-admins and short-circuits every other policy.
        $support = User::factory()->create();
        $support->assignRole(Role::Support->value);

        Gate::define('some.custom.ability', static fn (): bool => true);

        $this->assertTrue(Gate::forUser($support)->allows('some.custom.ability'));
    }

    #[Test]
    public function billing_staff_cannot_touch_infrastructure(): void
    {
        $billing = User::factory()->create();
        $billing->assignRole(Role::BillingAdmin->value);

        $this->assertTrue(Gate::forUser($billing)->allows(Permission::InvoiceManage->value));
        $this->assertTrue(Gate::forUser($billing)->allows(Permission::PaymentRefund->value));

        $this->assertFalse(Gate::forUser($billing)->allows(Permission::VmManage->value));
        $this->assertFalse(Gate::forUser($billing)->allows(Permission::BmcAccess->value));
        $this->assertFalse(Gate::forUser($billing)->allows(Permission::IpamManage->value));
    }

    #[Test]
    public function infrastructure_staff_cannot_issue_refunds(): void
    {
        $infra = User::factory()->create();
        $infra->assignRole(Role::InfrastructureAdmin->value);

        $this->assertTrue(Gate::forUser($infra)->allows(Permission::VmManage->value));
        $this->assertTrue(Gate::forUser($infra)->allows(Permission::BmcAccess->value));

        $this->assertFalse(Gate::forUser($infra)->allows(Permission::PaymentRefund->value));
        $this->assertFalse(Gate::forUser($infra)->allows(Permission::PricingManage->value));
        $this->assertFalse(Gate::forUser($infra)->allows(Permission::RoleManage->value));
    }

    #[Test]
    public function support_staff_can_read_but_not_mutate_billing_or_infrastructure(): void
    {
        $support = User::factory()->create();
        $support->assignRole(Role::Support->value);

        $this->assertTrue(Gate::forUser($support)->allows(Permission::InvoiceViewAny->value));
        $this->assertTrue(Gate::forUser($support)->allows(Permission::TicketReply->value));

        $this->assertFalse(Gate::forUser($support)->allows(Permission::InvoiceManage->value));
        $this->assertFalse(Gate::forUser($support)->allows(Permission::PaymentRefund->value));
        $this->assertFalse(Gate::forUser($support)->allows(Permission::ServiceTerminate->value));
        $this->assertFalse(Gate::forUser($support)->allows(Permission::InfrastructureManage->value));
    }

    #[Test]
    public function a_plain_customer_holds_no_staff_permission(): void
    {
        $customer = User::factory()->create();
        $customer->assignRole(Role::Customer->value);

        $staffOnly = array_filter(
            Permission::cases(),
            static fn (Permission $p): bool => $p !== Permission::CatalogView,
        );

        foreach ($staffOnly as $permission) {
            $this->assertFalse(
                Gate::forUser($customer)->allows($permission->value),
                "A customer must not hold {$permission->value}.",
            );
        }
    }

    #[Test]
    public function a_user_with_no_role_is_denied_everything(): void
    {
        $nobody = User::factory()->create();

        foreach (Permission::cases() as $permission) {
            $this->assertFalse(Gate::forUser($nobody)->allows($permission->value));
        }
    }

    #[Test]
    public function only_the_network_engineer_and_infrastructure_roles_manage_ipam(): void
    {
        $expected = [Role::InfrastructureAdmin, Role::NetworkEngineer];

        foreach (Role::cases() as $role) {
            if ($role === Role::SuperAdmin) {
                continue;
            }

            $user = User::factory()->create();
            $user->assignRole($role->value);

            $this->assertSame(
                in_array($role, $expected, strict: true),
                Gate::forUser($user)->allows(Permission::IpamManage->value),
                "Unexpected IPAM authority for {$role->value}.",
            );
        }
    }
}
