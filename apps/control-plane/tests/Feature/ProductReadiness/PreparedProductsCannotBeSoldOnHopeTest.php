<?php

declare(strict_types=1);

namespace Tests\Feature\ProductReadiness;

use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product as CatalogueProduct;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssessAllProducts;
use Lynomia\Modules\ProductReadiness\Application\Actions\AssessProduct;
use Lynomia\Modules\ProductReadiness\Domain\Enums\Product;
use Lynomia\Modules\ProductReadiness\Domain\Enums\ProductReadinessState;
use Lynomia\Modules\ProductReadiness\Domain\Exceptions\ProductNotSellable;
use Lynomia\Modules\ProductReadiness\Infrastructure\Models\ProductReadiness;
use Lynomia\Modules\Providers\Domain\Enums\ProviderCategory;
use Lynomia\Modules\Providers\Domain\Enums\ProviderState;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderCapability;
use Lynomia\Modules\Providers\Infrastructure\Models\ProviderInstance;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Lynomia\Modules\Shared\Domain\Enums\DeploymentEnvironment;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The scope addendum's readiness invariants, over HTTP and through the
 * checkout:
 *
 *   - a prepared product cannot be declared sellable however live its
 *     providers are, and the refusal names the software, not a rung;
 *   - a readiness-only product (Kubernetes) is visible and refused the
 *     same way;
 *   - in production a new sale is refused while the product is not
 *     ready_to_sell, and allowed once it is;
 *   - when a product loses readiness, existing services are not touched
 *     and only NEW sales stop;
 *   - outside production the guard stands aside, which is what lets every
 *     other test in this suite place an order on fakes.
 */
final class PreparedProductsCannotBeSoldOnHopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::SuperAdmin->value]);

        return $user;
    }

    private function live(ProviderCategory $category, string $driver = 'real'): ProviderInstance
    {
        $provider = ProviderInstance::factory()->of($category)->enabled()->create([
            'driver' => $driver,
            'environment' => DeploymentEnvironment::Production,
            'endpoint' => 'https://'.$driver.'.'.$category->value.'.example.test',
        ]);

        foreach ($category->capabilities() as $capability) {
            ProviderCapability::factory()->named($capability)->create(['provider_instance_id' => $provider->getKey()]);
        }

        return $provider;
    }

    private function everythingLive(): void
    {
        foreach (ProviderCategory::cases() as $category) {
            $this->live($category);
        }
    }

    private function plan(): Plan
    {
        $product = CatalogueProduct::factory()->create();
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9000,
            'setup_amount_minor' => 0,
        ]);

        return $plan->fresh(['prices', 'product']);
    }

    private function checkout(Plan $plan, string $key): CheckoutRequest
    {
        return new CheckoutRequest(
            lines: [new CheckoutLine($plan->id, 1)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: null,
            idempotencyKey: $key,
        );
    }

    private function inProduction(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
    }

    #[Test]
    public function every_prepared_product_is_on_the_screen_with_its_software_state_and_its_answers(): void
    {
        $response = $this->actingAs($this->operator())->getJson('/api/admin/readiness/products');

        $response->assertOk()->assertJsonCount(count(Product::cases()), 'data');

        $byProduct = collect($response->json('data'))->keyBy('product');

        $this->assertSame('prepared', $byProduct['cdn']['software']);
        $this->assertSame('prepared', $byProduct['object_storage']['software']);
        $this->assertSame('prepared', $byProduct['gpu_compute']['software']);
        $this->assertSame('prepared', $byProduct['email_hosting']['software']);
        $this->assertSame('readiness_only', $byProduct['managed_kubernetes']['software']);
        $this->assertSame('complete', $byProduct['vps']['software']);

        foreach ($byProduct as $row) {
            $this->assertSame('not_ready', $row['state']);
            $this->assertSame('no', $row['answers']['sellable']);
            $this->assertSame('no', $row['answers']['provider']);
            $this->assertArrayHasKey('next_action', $row);
        }

        $this->assertSame('no', $byProduct['cdn']['answers']['software']);
        $this->assertSame('yes', $byProduct['vps']['answers']['software']);
        // Kubernetes leans on four products; the dependency view says so.
        $this->assertSame(['vps', 'dns', 'backups', 'object_storage'], $byProduct['managed_kubernetes']['depends_on']);
    }

    #[Test]
    public function a_prepared_product_on_real_live_providers_is_refused_a_declaration_because_of_its_software(): void
    {
        $this->everythingLive();

        $assessed = $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/cdn/assess');
        $assessed->assertOk();
        $assessed->assertJsonPath('data.state', 'ready_for_real_validation');
        $assessed->assertJsonPath('data.blocker', 'not_implemented');
        $assessed->assertJsonPath('data.next_action', 'controlCenter.guidance.notImplemented');
        $assessed->assertJsonPath('data.answers.provider', 'yes');
        $assessed->assertJsonPath('data.answers.capabilities', 'yes');
        $assessed->assertJsonPath('data.answers.production', 'no');

        $declared = $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/cdn/sellable', [
            'reason' => 'The Cloudflare account answered.',
            'validation_reference' => 'TICKET-9',
        ]);

        $declared->assertConflict();
        $declared->assertJsonPath('error.code', 'readiness_refused');
        $this->assertStringContainsString('software is prepared', $declared->json('error.message'));
        $this->assertSame(ProductReadinessState::ReadyForRealValidation, ProductReadiness::query()->where('product', 'cdn')->firstOrFail()->state);
    }

    #[Test]
    public function kubernetes_is_visible_in_readiness_and_can_never_become_a_product_by_declaration(): void
    {
        $this->everythingLive();

        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/assess')->assertOk();

        $row = $this->actingAs($this->operator())->getJson('/api/admin/readiness/products/managed_kubernetes');
        $row->assertOk();
        $row->assertJsonPath('data.software', 'readiness_only');
        $row->assertJsonPath('data.state', 'ready_for_real_validation');
        $row->assertJsonPath('data.blocker', 'not_implemented');

        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/managed_kubernetes/sellable', [
            'reason' => 'Everything answered.',
            'validation_reference' => 'TICKET-10',
        ])->assertConflict()->assertJsonPath('error.code', 'readiness_refused');

        $this->assertDatabaseMissing('audit_log', ['action' => AuditAction::ProductDeclaredSellable->value]);
    }

    #[Test]
    public function a_controlled_provider_cannot_make_a_prepared_product_look_readier_than_ready_for_test(): void
    {
        foreach (ProviderCategory::cases() as $category) {
            $this->live($category, 'fake');
        }

        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/object_storage/assess')
            ->assertOk()
            ->assertJsonPath('data.state', 'ready_for_test');
    }

    #[Test]
    public function in_production_a_new_order_is_refused_until_the_product_is_ready_to_sell(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $plan = $this->plan();

        $this->inProduction();

        try {
            app(PlaceOrder::class)->execute($customer, $this->checkout($plan, 'k-1'));
            $this->fail('A VPS order was placed in production with the VPS product not ready to sell.');
        } catch (ProductNotSellable $refused) {
            $this->assertSame('product.not_sellable', $refused->errorCode());
            $this->assertSame(409, $refused->httpStatus());
            $this->assertSame(['product' => 'vps', 'readiness' => 'not_ready'], $refused->context());
        }

        $this->assertSame(0, Order::query()->count());

        // A person declares it, on the strength of real live providers.
        $this->app->detectEnvironment(static fn (): string => 'testing');
        $this->everythingLive();
        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/vps/assess')->assertJsonPath('data.state', 'ready_for_production');
        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/vps/sellable', [
            'reason' => 'Ten machines built and destroyed on the live cluster.',
            'validation_reference' => 'docs/validation/vps-2026-09.md',
        ])->assertOk()->assertJsonPath('data.state', 'ready_to_sell');

        $this->inProduction();
        $order = app(PlaceOrder::class)->execute($customer, $this->checkout($plan, 'k-2'));
        $this->assertSame(OrderStatus::PendingPayment, $order->status);
    }

    #[Test]
    public function outside_production_the_guard_stands_aside_so_the_sale_can_be_rehearsed(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $order = app(PlaceOrder::class)->execute($customer, $this->checkout($this->plan(), 'k-3'));

        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertSame(ProductReadinessState::NotReady, app(AssessProduct::class)->execute(Product::Vps)->state);
    }

    #[Test]
    public function losing_readiness_stops_new_sales_and_leaves_existing_services_exactly_where_they_were(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $plan = $this->plan();
        $this->everythingLive();
        $compute = ProviderInstance::query()->where('category', ProviderCategory::Compute->value)->firstOrFail();

        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/vps/assess')->assertJsonPath('data.state', 'ready_for_production');
        $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/vps/sellable', [
            'reason' => 'Validated.',
            'validation_reference' => 'TICKET-1',
        ])->assertOk();

        // A customer already holds a service on a subscription.
        $service = Service::factory()->status(ServiceStatus::Active)->create(['customer_id' => $customer->getKey()]);
        $subscription = Subscription::factory()->priced(9000)->create([
            'customer_id' => $customer->getKey(),
            'status' => SubscriptionStatus::Active,
            'current_period_end' => CarbonImmutable::now()->addDays(10),
        ]);

        // The compute provider is disabled by an operator. The event it
        // raises reassesses every product in the same transaction.
        $this->actingAs($this->operator())
            ->postJson('/api/admin/providers/'.$compute->getKey().'/disable', ['reason' => 'Cluster in maintenance.'])
            ->assertOk();

        $this->assertSame(ProviderState::Disabled, $compute->fresh()->state);
        $this->assertSame(ProductReadinessState::NotReady, ProductReadiness::query()->where('product', 'vps')->firstOrFail()->state);
        $this->assertDatabaseHas('audit_log', ['action' => AuditAction::ProductSellabilityWithdrawn->value]);

        // New sale: refused, in production.
        $this->inProduction();
        $this->expectException(ProductNotSellable::class);

        try {
            app(PlaceOrder::class)->execute($customer, $this->checkout($plan, 'k-4'));
        } finally {
            // Existing service and subscription: untouched. Nothing in the
            // readiness module knows they exist, and this proves it.
            $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
            $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
            $this->assertNull($subscription->fresh()->cancelled_at);
        }
    }

    #[Test]
    public function the_sweep_examines_every_product_including_the_prepared_ones(): void
    {
        $response = $this->actingAs($this->operator())->postJson('/api/admin/readiness/products/assess');

        $response->assertOk()->assertJsonPath('data.examined', count(Product::cases()));
        $this->assertSame(count(Product::cases()), ProductReadiness::query()->count());
        $this->assertSame(count(Product::cases()), count(app(AssessAllProducts::class)->execute()['products']));
    }
}
