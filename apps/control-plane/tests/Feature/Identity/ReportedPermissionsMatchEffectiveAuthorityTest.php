<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /me` reports what a login may do, not what rows are attached to it.
 *
 * Found by a browser test: a Super Admin holds no permission rows — every
 * ability is granted by a Gate::before rule, on purpose, so that a permission
 * added later is not silently missing from the role — and the endpoint was
 * reporting those rows verbatim. The portal reads this list to decide whether
 * to show the operator area, so the platform's most privileged account was
 * shown a customer portal and redirected away from every operator screen it
 * typed the address of. Nothing was insecure; the screens were simply
 * unreachable, and no test noticed because every backend test asserts through
 * the gate rather than through this list.
 */
final class ReportedPermissionsMatchEffectiveAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles and their permissions come from the seeder rather than from
        // fixtures written here, so this asserts against the platform's own
        // idea of what each role may do.
        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function a_super_admin_is_reported_with_the_authority_the_gate_actually_grants(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertOk();

        /** @var list<string> $reported */
        $reported = $response->json('data.permissions');

        // The gate says yes to everything, so the list has to as well.
        $this->assertTrue($user->can(Permission::InfrastructureView->value));
        $this->assertContains(Permission::InfrastructureView->value, $reported);
        $this->assertCount(count(Permission::cases()), $reported);
    }

    #[Test]
    public function a_billing_administrator_is_not_reported_as_holding_infrastructure_authority(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::BillingAdmin->value]);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        /** @var list<string> $reported */
        $reported = $response->json('data.permissions');

        // The half of the boundary that matters: reporting *more* than the
        // login holds would put operator screens in front of somebody the API
        // will refuse, which is a worse experience than hiding them and a
        // misleading one about who may do what.
        $this->assertContains(Permission::InvoiceViewAny->value, $reported);
        $this->assertNotContains(Permission::InfrastructureView->value, $reported);
        $this->assertNotContains(Permission::ProvisioningView->value, $reported);
    }

    #[Test]
    public function a_customer_is_reported_with_the_baseline_and_nothing_else(): void
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::Customer->value]);

        $response = $this->actingAs($user)->getJson('/api/v1/me');

        /** @var list<string> $reported */
        $reported = $response->json('data.permissions');

        $this->assertSame([Permission::CatalogView->value], $reported);
    }
}
