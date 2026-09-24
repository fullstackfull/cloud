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
use Lynomia\Modules\Rbac\Domain\Enums\Permission;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may change the price list, and who may only look at one.
 *
 * ===========================================================================
 * WHY `catalog.view` GUARDS NOTHING HERE
 * ===========================================================================
 *
 * It is the permission a plain customer holds. `Role::Customer` carries it and
 * nothing else — it is the baseline every customer login gets — and
 * `AuthorizationTest` treats every other permission as staff-only by
 * explicitly excluding this one.
 *
 * So `catalog.view` is the obvious-looking guard for an operator listing and
 * is exactly the wrong one: it would have handed every customer the operator
 * catalogue, which shows withdrawn products, unlisted plans, both languages
 * and every price whether active or not. The reads are `catalog.manage` for
 * that reason, and this test is what would notice if somebody "simplified" it
 * back.
 *
 * ===========================================================================
 * AND WHY PRICING IS SEPARATE
 * ===========================================================================
 *
 * Describing a thing and deciding what it costs are different acts. A role
 * may legitimately do the first and not the second, so the price endpoints
 * ask for `pricing.manage` and the rest ask for `catalog.manage`.
 */
final class OnlyAnOperatorMayChangeWhatIsSoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return list<array{string, string}>
     */
    public static function mutations(): array
    {
        return [
            ['POST', '/api/admin/catalogue/products'],
            ['POST', '/api/admin/catalogue/plans'],
            ['GET', '/api/admin/catalogue/products'],
            ['GET', '/api/admin/catalogue/plans'],
            ['GET', '/api/admin/catalogue/hosting-packages'],
            ['POST', '/api/admin/catalogue/hosting-packages'],
        ];
    }

    #[Test]
    #[DataProvider('mutations')]
    public function nobody_unauthenticated_reaches_the_catalogue(string $method, string $uri): void
    {
        $this->json($method, $uri, [])->assertUnauthorized();
    }

    #[Test]
    #[DataProvider('mutations')]
    public function a_customer_is_refused_even_though_they_hold_catalog_view(string $method, string $uri): void
    {
        $customer = User::factory()->create();
        $customer->syncRoles([Role::Customer->value]);

        // The premise, asserted rather than assumed: this is the permission
        // that makes the guard choice load-bearing.
        $this->assertTrue($customer->can(Permission::CatalogView->value));

        $this->actingAs($customer)->json($method, $uri, [])->assertForbidden();
    }

    #[Test]
    #[DataProvider('mutations')]
    public function an_administrator_without_the_permission_is_refused(string $method, string $uri): void
    {
        // Noc is staff, holds a wide set of infrastructure permissions, and
        // has no business repricing anything.
        $noc = User::factory()->create();
        $noc->syncRoles([Role::Noc->value]);

        $this->actingAs($noc)->json($method, $uri, [])->assertForbidden();
    }

    #[Test]
    public function pricing_is_its_own_permission(): void
    {
        $plan = $this->plan();

        // Support holds neither catalogue permission.
        $support = User::factory()->create();
        $support->syncRoles([Role::Support->value]);

        $this->actingAs($support)
            ->postJson('/api/admin/catalogue/plans/'.$plan->getKey().'/prices', [])
            ->assertForbidden();

        // A billing administrator holds both, and gets past the guard: the
        // 422 is validation, which is the next gate rather than this one.
        $billing = User::factory()->create();
        $billing->syncRoles([Role::BillingAdmin->value]);

        $this->actingAs($billing)
            ->postJson('/api/admin/catalogue/plans/'.$plan->getKey().'/prices', [])
            ->assertUnprocessable();
    }

    /**
     * The price endpoints, against the same three refusals as everything else.
     *
     * They are not in the data provider above because they need a plan to
     * exist first, and a provider cannot make one. That exemption was a hole:
     * a deliberate breakage that put `catalog.view` — the permission every
     * customer holds — on the price route left this suite passing 19 of 19.
     * The one endpoint where the wrong guard actually moves money was the one
     * endpoint no customer test reached, which is the most expensive shape a
     * gap in a gate can have.
     */
    #[Test]
    public function nobody_without_pricing_authority_reaches_the_price_endpoints(): void
    {
        $plan = $this->plan();
        $set = '/api/admin/catalogue/plans/'.$plan->getKey().'/prices';
        $withdraw = $set.'/'.$this->price($plan);

        $calls = [['POST', $set], ['DELETE', $withdraw]];

        /*
         * Every unauthenticated call first, before anything authenticates.
         * `actingAs` persists for the rest of the test, so a loop that mixed
         * the two would make its second "unauthenticated" request as whoever
         * the first iteration logged in — and read 403 where it meant 401,
         * which is how a test comes to assert the wrong refusal and pass.
         */
        foreach ($calls as [$method, $uri]) {
            $this->json($method, $uri, [])->assertUnauthorized();
        }

        // A customer, who holds catalog.view and nothing else.
        $customer = User::factory()->create();
        $customer->syncRoles([Role::Customer->value]);
        $this->assertTrue($customer->can(Permission::CatalogView->value));

        foreach ($calls as [$method, $uri]) {
            $this->actingAs($customer)->json($method, $uri, [])->assertForbidden();
        }

        // Staff with no pricing authority.
        $noc = User::factory()->create();
        $noc->syncRoles([Role::Noc->value]);

        foreach ($calls as [$method, $uri]) {
            $this->actingAs($noc)->json($method, $uri, [])->assertForbidden();
        }
    }

    private function plan(): Plan
    {
        return Plan::factory()->for(Product::factory()->create(['kind' => 'vps']))->create();
    }

    private function price(Plan $plan): string
    {
        return (string) PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
        ])->getKey();
    }
}
