<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingPackage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Taking something off sale, and the things that must not happen when you do.
 *
 * Withdrawal is `is_active = false`, never a delete, because orders and
 * subscriptions point at these rows and "what did I buy" has to stay
 * answerable. It is also not a lifecycle event: an operator tidying a price
 * list must not be able to end a customer's server by doing it.
 *
 * And it is reversible, which is the half people forget. An operator who
 * withdrew the wrong plan needs a route back that is not a support ticket, so
 * recording the same slug again re-lists it.
 *
 * What the customer then sees is not asserted here, deliberately.
 * `CatalogueBrowsingTest` already pins it — an on-sale product is listed, a
 * withdrawn one and an unlisted one are not — against the customer surface
 * with its own fixtures. A second copy here would assert the same scope twice
 * and drift from it the first time that surface changes.
 */
final class WithdrawingSomethingStopsFutureSalesAndNothingElseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function a_withdrawn_plan_is_deactivated_and_recorded_again_to_re_list_it(): void
    {
        $operator = $this->operator();

        $product = Product::factory()->create(['kind' => 'vps', 'slug' => 'cloud-vps']);
        $plan = Plan::factory()->for($product)->create(['slug' => 'cloud-vps-1', 'is_active' => true]);

        $this->actingAs($operator)
            ->deleteJson('/api/admin/catalogue/plans/'.$plan->getKey())
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Still there. A delete would have taken the row an order points at.
        $this->assertDatabaseHas('plans', ['id' => $plan->getKey(), 'is_active' => false]);
        $this->assertNull($plan->fresh()->deleted_at);

        // Recording it again brings it back, under the same id.
        $this->actingAs($operator)->postJson('/api/admin/catalogue/plans', [
            'product_id' => $product->getKey(),
            'slug' => 'cloud-vps-1',
            'name' => ['en' => 'VPS 1', 'ar' => 'خادم ١'],
            'resources' => ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 80],
            'is_active' => true,
            'is_public' => true,
        ])->assertOk()->assertJsonPath('data.id', (string) $plan->getKey());

        $this->assertTrue((bool) $plan->fresh()->is_active);
    }

    #[Test]
    public function a_withdrawn_product_keeps_its_plans_and_prices(): void
    {
        $operator = $this->operator();

        $product = Product::factory()->create(['kind' => 'vps']);
        $plan = Plan::factory()->for($product)->create();
        $price = PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
        ]);

        $this->actingAs($operator)
            ->deleteJson('/api/admin/catalogue/products/'.$product->getKey())
            ->assertOk();

        // Nothing cascaded. The product is off sale; the rows history points
        // at are exactly where they were.
        $this->assertDatabaseHas('plans', ['id' => $plan->getKey()]);
        $this->assertDatabaseHas('plan_prices', ['id' => $price->getKey(), 'recurring_amount_minor' => 9_000]);
    }

    #[Test]
    public function a_plan_that_would_silently_sell_a_default_machine_is_refused(): void
    {
        $operator = $this->operator();
        $product = Product::factory()->create(['kind' => 'vps']);

        $this->actingAs($operator)->postJson('/api/admin/catalogue/plans', [
            'product_id' => $product->getKey(),
            'slug' => 'unspecified',
            'name' => ['en' => 'Unspecified', 'ar' => 'غير محدد'],
            'resources' => ['bandwidth_tib' => 2],
            'is_active' => true,
            'is_public' => true,
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'catalogue_refused');

        $this->assertSame(0, Plan::query()->count());
    }

    #[Test]
    public function a_dedicated_plan_with_no_hardware_profile_is_refused(): void
    {
        $operator = $this->operator();
        $product = Product::factory()->create(['kind' => 'dedicated']);

        $this->actingAs($operator)->postJson('/api/admin/catalogue/plans', [
            'product_id' => $product->getKey(),
            'slug' => 'profileless',
            'name' => ['en' => 'Profileless', 'ar' => 'بلا ملف'],
            'resources' => ['vcpu' => 8],
            'is_active' => true,
            'is_public' => true,
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'catalogue_refused');

        $this->assertSame(0, Plan::query()->count());
    }

    #[Test]
    public function a_hosting_package_cannot_be_mapped_onto_a_plan_the_panel_never_sees(): void
    {
        $operator = $this->operator();

        $vps = Product::factory()->create(['kind' => 'vps']);
        $plan = Plan::factory()->for($vps)->create();

        $this->actingAs($operator)->postJson('/api/admin/catalogue/hosting-packages', [
            'slug' => 'wrong-kind',
            'panel_package_name' => 'lyn_wrong',
            'plan_id' => $plan->getKey(),
            'is_active' => true,
        ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'catalogue_refused');

        $this->assertSame(0, HostingPackage::query()->count());
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::BillingAdmin->value]);

        return $user;
    }
}
