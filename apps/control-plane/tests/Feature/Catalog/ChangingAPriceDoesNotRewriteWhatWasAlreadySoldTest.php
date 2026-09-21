<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\PlaceableEstate;
use Tests\TestCase;

/**
 * Money already charged is evidence about the past, and the past is closed.
 *
 * ===========================================================================
 * THE PROPERTY
 * ===========================================================================
 *
 * An operator can now change a price through an API. That makes a question
 * urgent which was previously theoretical, because nothing could change a
 * price at all: what happens to the orders raised at the old one?
 *
 * Nothing. `order_items` snapshots the unit amounts, the name, the period and
 * the resources at the moment of sale, and its `plan_id` is `nullOnDelete` so
 * the line survives the catalogue outright. The schema comment says why —
 * "re-deriving this from the catalogue later would silently rewrite history
 * the first time a price changes" — and this is the test that makes the
 * comment true rather than aspirational.
 *
 * The failure it exists to catch is quiet and expensive. A total recomputed
 * from the live catalogue would restate somebody's paid invoice the next time
 * a price moved, and nothing would report an error: the number on the screen
 * would simply stop matching the number on the card statement.
 */
final class ChangingAPriceDoesNotRewriteWhatWasAlreadySoldTest extends TestCase
{
    use PlaceableEstate;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Checkout refuses a plan the platform cannot say where to build.
        // These tests are about something else, so they are given an
        // estate to be placeable on rather than an exemption.
        $this->estateThatCanPlaceAVps();

        $this->seed(RolePermissionSeeder::class);
    }

    #[Test]
    public function an_order_raised_at_the_old_price_keeps_it(): void
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $plan = Plan::factory()->for($product)->create();

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
            'setup_amount_minor' => 0,
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create(['currency' => 'KWD']);

        $order = app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
            lines: [new CheckoutLine($plan->id, 1)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: null,
            idempotencyKey: (string) Str::ulid(),
        ));

        $item = $order->items()->firstOrFail();

        $this->assertSame(9_000, (int) $item->unit_recurring_minor);
        $soldTotal = (int) $order->total_minor;

        // The operator raises the price, through the supported path.
        $this->actingAs($this->operator())
            ->postJson('/api/admin/catalogue/plans/'.$plan->getKey().'/prices', [
                'currency' => 'KWD',
                'billing_period' => 'monthly',
                'recurring_amount_minor' => 12_000,
                'setup_amount_minor' => 0,
                'is_active' => true,
            ])
            ->assertOk();

        // The catalogue moved.
        $this->assertSame(
            12_000,
            (int) PlanPrice::query()
                ->where('plan_id', $plan->getKey())
                ->where('currency', 'KWD')
                ->where('billing_period', 'monthly')
                ->value('recurring_amount_minor'),
        );

        // The sale did not.
        $reread = Order::query()->findOrFail($order->getKey());

        $this->assertSame($soldTotal, (int) $reread->total_minor);
        $this->assertSame(9_000, (int) $reread->items()->firstOrFail()->unit_recurring_minor);
    }

    #[Test]
    public function withdrawing_a_price_leaves_the_row_and_the_history(): void
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $plan = Plan::factory()->for($product)->create();

        $price = PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
            'is_active' => true,
        ]);

        $this->actingAs($this->operator())
            ->deleteJson('/api/admin/catalogue/plans/'.$plan->getKey().'/prices/'.$price->getKey())
            ->assertOk();

        // Deactivated, not destroyed: an invoice raised at this price must
        // still be explainable.
        $this->assertDatabaseHas('plan_prices', [
            'id' => $price->getKey(),
            'is_active' => false,
            'recurring_amount_minor' => 9_000,
        ]);
    }

    #[Test]
    public function one_plan_currency_and_period_resolves_to_one_price(): void
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $plan = Plan::factory()->for($product)->create();
        $operator = $this->operator();

        $body = [
            'currency' => 'KWD',
            'billing_period' => 'monthly',
            'recurring_amount_minor' => 9_000,
            'is_active' => true,
        ];

        $this->actingAs($operator)->postJson('/api/admin/catalogue/plans/'.$plan->getKey().'/prices', $body)
            ->assertCreated();

        // The second call is the same operator correcting the amount, not a
        // second price for the same three things. `array_merge` rather than
        // `+`, because the union operator keeps the left-hand value and the
        // second request would have carried the first amount again — a test
        // that asserted nothing while appearing to.
        $this->actingAs($operator)->postJson(
            '/api/admin/catalogue/plans/'.$plan->getKey().'/prices',
            array_merge($body, ['recurring_amount_minor' => 11_000]),
        )->assertOk();

        // One row, and it is the corrected one.
        $this->assertSame(1, PlanPrice::query()->where('plan_id', $plan->getKey())->count());
        $this->assertSame(
            11_000,
            (int) PlanPrice::query()->where('plan_id', $plan->getKey())->value('recurring_amount_minor'),
        );
    }

    #[Test]
    public function money_that_is_not_whole_minor_units_is_refused(): void
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $plan = Plan::factory()->for($product)->create();
        $operator = $this->operator();

        foreach ([9.5, '9.000', -1] as $amount) {
            $this->actingAs($operator)
                ->postJson('/api/admin/catalogue/plans/'.$plan->getKey().'/prices', [
                    'currency' => 'KWD',
                    'billing_period' => 'monthly',
                    'recurring_amount_minor' => $amount,
                    'is_active' => true,
                ])
                ->assertUnprocessable();
        }

        $this->assertSame(0, PlanPrice::query()->count());
    }

    #[Test]
    public function a_currency_the_platform_cannot_charge_is_refused(): void
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $plan = Plan::factory()->for($product)->create();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/catalogue/plans/'.$plan->getKey().'/prices', [
                'currency' => 'ZZZ',
                'billing_period' => 'monthly',
                'recurring_amount_minor' => 9_000,
                'is_active' => true,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'catalogue_refused');

        $this->assertSame(0, PlanPrice::query()->count());
    }

    private function operator(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([Role::BillingAdmin->value]);

        return $user;
    }
}
