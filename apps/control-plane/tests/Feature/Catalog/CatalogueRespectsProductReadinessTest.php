<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product as CatalogueProduct;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\ProductReadiness\Application\Services\ProductSellability;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The catalogue offers a product if, and only if, the order guard would
 * accept a sale of it.
 *
 * Before Wave 0 the guard decided alone at order time, and a product the
 * readiness ladder had not cleared stayed on the shelf with a "Place order"
 * button that answered 409. Both now read {@see ProductSellability}, and this
 * suite walks the ladder rung by rung asserting that the listing, the direct
 * product link, the direct plan link and the guard agree at every step —
 * including the two rungs a controlled provider can reach, which must never
 * put anything on sale.
 */
final class CatalogueRespectsProductReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private User $shopper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $product = CatalogueProduct::factory()->create(['kind' => 'vps', 'slug' => 'cloud-vps', 'is_active' => true, 'is_public' => true]);
        $this->plan = Plan::factory()->create(['product_id' => $product->getKey(), 'is_active' => true, 'is_public' => true]);
        PlanPrice::factory()->create([
            'plan_id' => $this->plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9000,
            'setup_amount_minor' => 0,
        ]);

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $this->shopper = User::factory()->create();
        $customer->members()->create(['user_id' => $this->shopper->getKey(), 'role' => CustomerRole::Owner, 'accepted_at' => now()]);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }

    private function inProduction(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        // The framework only stands its CSRF check aside while it believes it
        // is running unit tests, which it decides from the environment name.
        // The check is not what this suite is about.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function outOfProduction(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'testing');
    }

    /**
     * A provider the Providers module would call proven and an operator has
     * enabled, written directly: the real drivers have no tester in this
     * build, so this is the only road to a real, live provider in a test.
     */
    private function live(ProviderCategory $category, string $driver, DeploymentEnvironment $environment = DeploymentEnvironment::Production): void
    {
        $provider = ProviderInstance::factory()->of($category)->enabled()->create([
            'driver' => $driver,
            'environment' => $environment,
            'endpoint' => 'https://'.$driver.'.'.$category->value.'.example.test',
        ]);

        foreach ($category->capabilities() as $capability) {
            ProviderCapability::factory()->named($capability)->create(['provider_instance_id' => $provider->getKey()]);
        }
    }

    private function everythingLive(string $driver = 'real', DeploymentEnvironment $environment = DeploymentEnvironment::Production): void
    {
        foreach (ProviderCategory::cases() as $category) {
            $this->live($category, $driver, $environment);
        }
    }

    private function assess(): TestResponse
    {
        return $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/vps/assess');
    }

    private function declareSellable(): TestResponse
    {
        return $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/vps/sellable', [
            'reason' => 'Ten machines built and destroyed on the live cluster.',
            'validation_reference' => 'docs/validation/vps-2026-09.md',
        ]);
    }

    /**
     * The three roads to a product, and the guard, must all say the same.
     */
    private function assertOffered(bool $expected, string $because): void
    {
        $listing = $this->actingAs($this->shopper)->getJson('/api/v1/catalog/products')->assertOk();
        $listed = in_array('cloud-vps', array_column((array) $listing->json('data'), 'slug'), strict: true);

        $bySlug = $this->actingAs($this->shopper)->getJson('/api/v1/catalog/products/cloud-vps')->getStatusCode();
        $byPlan = $this->actingAs($this->shopper)->getJson('/api/v1/catalog/plans/'.$this->plan->getKey())->getStatusCode();

        $guard = app(ProductSellability::class)->maySell(Product::Vps);

        $this->assertSame($expected, $listed, 'listing: '.$because);
        $this->assertSame($expected ? 200 : 404, $bySlug, 'product by slug: '.$because);
        $this->assertSame($expected ? 200 : 404, $byPlan, 'plan by id: '.$because);
        $this->assertSame($expected, $guard, 'order guard: '.$because);
    }

    #[Test]
    public function in_production_a_product_that_is_not_ready_is_absent_by_every_road(): void
    {
        $this->inProduction();

        $this->assertOffered(false, 'nothing has been assessed, so the product is not_ready');
    }

    #[Test]
    public function a_controlled_provider_reaches_ready_for_test_and_puts_nothing_on_sale(): void
    {
        // Enabled, in production, fully capable — and every one a fake. The
        // ladder caps this at the rehearsal rung, and the shelf follows it.
        $this->everythingLive(driver: 'fake');
        $this->assess()->assertJsonPath('data.state', 'ready_for_test');

        $this->inProduction();
        $this->assertOffered(false, 'ready_for_test is a rehearsal rung');

        // Nor can a person declare over it.
        $this->outOfProduction();
        $this->declareSellable()->assertConflict();
        $this->inProduction();
        $this->assertOffered(false, 'a declaration over a fake is refused');
    }

    #[Test]
    public function real_providers_outside_production_reach_real_validation_and_put_nothing_on_sale(): void
    {
        $this->everythingLive(driver: 'real', environment: DeploymentEnvironment::Staging);
        $this->assess()->assertJsonPath('data.state', 'ready_for_real_validation');

        $this->inProduction();
        $this->assertOffered(false, 'ready_for_real_validation: somebody real answered, nothing real has been sold');
    }

    #[Test]
    public function ready_for_production_without_a_persons_declaration_puts_nothing_on_sale(): void
    {
        $this->everythingLive();
        $this->assess()->assertJsonPath('data.state', 'ready_for_production');

        $this->inProduction();
        $this->assertOffered(false, 'ready_for_production is the platform\'s conclusion; selling needs a person\'s');
    }

    #[Test]
    public function ready_to_sell_puts_the_product_on_the_shelf_and_the_checkout_accepts_it(): void
    {
        $this->everythingLive();
        $this->assess()->assertJsonPath('data.state', 'ready_for_production');
        $this->declareSellable()->assertOk()->assertJsonPath('data.state', 'ready_to_sell');

        $this->inProduction();
        $this->assertOffered(true, 'ready_to_sell');

        $this->actingAs($this->shopper)
            ->withHeader('Idempotency-Key', 'production-checkout-0001')
            ->postJson('/api/v1/orders', [
                'items' => [['plan_id' => $this->plan->getKey(), 'quantity' => 1]],
                'billing_period' => BillingPeriod::Monthly->value,
            ])
            ->assertCreated();

        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function a_provider_falling_over_takes_the_product_off_the_shelf_in_the_same_transaction(): void
    {
        $this->everythingLive();
        $this->assess();
        $this->declareSellable()->assertOk();
        $compute = ProviderInstance::query()->where('category', ProviderCategory::Compute->value)->firstOrFail();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/providers/'.$compute->getKey().'/disable', ['reason' => 'Cluster in maintenance.'])
            ->assertOk();

        $this->inProduction();
        $this->assertOffered(false, 'the compute provider was disabled and the declaration withdrawn with it');

        $this->actingAs($this->shopper)
            ->withHeader('Idempotency-Key', 'production-checkout-0002')
            ->postJson('/api/v1/orders', [
                'items' => [['plan_id' => $this->plan->getKey(), 'quantity' => 1]],
                'billing_period' => BillingPeriod::Monthly->value,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'product.not_sellable');
    }

    #[Test]
    public function outside_production_the_shelf_is_full_so_the_sale_can_be_rehearsed(): void
    {
        $this->assertOffered(true, 'the guard stands aside outside production, and so does the shelf');
    }

    #[Test]
    public function readiness_is_never_inferred_from_the_catalogue_row_itself(): void
    {
        // A product with a price, a plan and a public flag is not a product
        // that may be sold: the shelf asks the ladder, never the row.
        $this->inProduction();

        $this->assertSame([], app(ProductSellability::class)->sellableCatalogueKinds());
        $this->assertOffered(false, 'a priced, public row proves nothing about readiness');
    }
}
