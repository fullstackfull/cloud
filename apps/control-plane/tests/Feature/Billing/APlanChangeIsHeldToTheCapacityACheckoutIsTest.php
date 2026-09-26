<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Application\Services\PlanCapacity;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * A plan change takes a unit of the plan it moves onto, and gives back the
 * unit of the plan it leaves - under the same rules a checkout obeys.
 *
 * `stock_limit` and `per_customer_limit` were enforced at checkout only
 * (F-06), and counted order lines by the plan they were bought on. A plan
 * change was a second way onto a plan that never asked: the re-audit moved a
 * subscription onto a plan with `stock_limit = 0` and got a 200 (interaction
 * F-01 x F-06). And because the count read the order line, the unit the
 * customer moved onto was never counted as taken, so the plan kept selling it.
 */
final class APlanChangeIsHeldToTheCapacityACheckoutIsTest extends BillingApiTestCase
{
    private Product $product;

    private Plan $small;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([RunProvisioningJob::class]);
        $this->freezeTime();

        $this->product = Product::factory()->create(['kind' => 'vps']);
        $this->small = $this->plan('small', 9_000);
    }

    #[Test]
    public function a_plan_that_is_sold_out_cannot_be_moved_onto(): void
    {
        $large = $this->plan('large', 18_000, stockLimit: 0);
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->boughtSubscription($customer, $this->small);

        $this->changePlan($user, $subscription, $large)
            ->assertStatus(409)
            ->assertJsonPath('error.details.refusals', 'out_of_stock');

        $this->assertSame($this->small->id, $subscription->fresh()?->plan_id);
        $this->assertSame(0, Invoice::query()->where('subscription_id', $subscription->getKey())->whereHas('items', fn ($q) => $q->where('kind', InvoiceItemKind::Proration->value))->count());
    }

    #[Test]
    public function a_per_customer_limit_counts_the_plans_the_customer_already_holds(): void
    {
        $large = $this->plan('large', 18_000, perCustomerLimit: 1);
        [$customer, $user] = $this->accountWithOwner();
        $this->boughtSubscription($customer, $large);
        $subscription = $this->boughtSubscription($customer, $this->small);

        $this->changePlan($user, $subscription, $large)
            ->assertStatus(409)
            ->assertJsonPath('error.details.refusals', 'per_customer_limit');

        $this->assertSame($this->small->id, $subscription->fresh()?->plan_id);
    }

    #[Test]
    public function the_options_screen_says_a_plan_is_sold_out_before_the_customer_confirms(): void
    {
        $large = $this->plan('large', 18_000, stockLimit: 0);
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->boughtSubscription($customer, $this->small);

        $options = collect((array) $this->actingAs($user)
            ->getJson("/api/v1/subscriptions/{$subscription->id}/plan-options")
            ->assertOk()
            ->json('data'))->keyBy('plan_id');

        $this->assertContains('out_of_stock', $options[$large->id]['refusals']);
    }

    #[Test]
    public function the_unit_moves_with_the_subscription(): void
    {
        $large = $this->plan('large', 18_000, stockLimit: 1);
        [$customer, $user] = $this->accountWithOwner();
        $subscription = $this->boughtSubscription($customer, $this->small);

        $capacity = app(PlanCapacity::class);
        $this->assertSame(1, $capacity->claimed($this->small->id));
        $this->assertSame(0, $capacity->claimed($large->id));

        $this->changePlan($user, $subscription, $large)->assertOk();

        $this->assertSame(0, $capacity->claimed($this->small->id), 'The unit left behind is free to sell again.');
        $this->assertSame(1, $capacity->claimed($large->id), 'The unit moved onto is taken.');
        $this->assertSame(1, $capacity->claimed($large->id, $customer));

        // And the last unit is not sold a second time, to anyone.
        [$other, $otherUser] = $this->accountWithOwner();
        $theirs = $this->boughtSubscription($other, $this->small);

        $this->changePlan($otherUser, $theirs, $large, 'capacity-other-1')
            ->assertStatus(409)
            ->assertJsonPath('error.details.refusals', 'out_of_stock');
    }

    // ---- helpers ------------------------------------------------------------

    private function changePlan(User $user, Subscription $subscription, Plan $plan, string $key = 'capacity-change-1'): TestResponse
    {
        return $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/subscriptions/{$subscription->id}/plan", [
                'plan_id' => $plan->id,
                'price_id' => PlanPrice::query()->where('plan_id', $plan->getKey())->sole()->id,
            ]);
    }

    private function plan(string $slug, int $minor, ?int $stockLimit = null, ?int $perCustomerLimit = null): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => $this->product->getKey(),
            'slug' => $slug,
            // No resources: the change is money and capacity only, with no
            // machine to resize and no disk to refuse.
            'resources' => [],
            'is_active' => true,
            'is_public' => true,
            'stock_limit' => $stockLimit,
            'per_customer_limit' => $perCustomerLimit,
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $minor,
            'setup_amount_minor' => 0,
            'is_active' => true,
        ]);

        return $plan;
    }

    /**
     * A subscription reached the way a real one is: an order line, paid, and
     * the service and subscription it fulfilled into.
     */
    private function boughtSubscription(Customer $customer, Plan $plan): Subscription
    {
        $recurring = PlanPrice::query()->where('plan_id', $plan->getKey())->sole()->recurring_amount_minor;

        $order = Order::factory()->paid()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);

        /** @var OrderItem $item */
        $item = OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'plan_id' => $plan->getKey(),
            'kind' => 'plan',
            'name' => $plan->slug,
            'billing_period' => BillingPeriod::Monthly,
            'quantity' => 1,
            'unit_recurring_minor' => $recurring,
            'unit_setup_minor' => 0,
            'total_minor' => $recurring,
        ]);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::now()->subDays(10))
            ->create([
                'customer_id' => $customer->getKey(),
                'order_id' => $order->getKey(),
                'plan_id' => $plan->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => $recurring,
            ]);

        $invoice = Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(),
            'order_id' => $order->getKey(),
            'subtotal_minor' => $recurring,
            'total_minor' => $recurring,
            'amount_paid_minor' => $recurring,
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(),
            'kind' => InvoiceItemKind::Plan,
            'description' => $plan->slug,
            'quantity' => 1,
            'unit_amount_minor' => $recurring,
            'total_minor' => $recurring,
        ]);

        Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'order_id' => $order->getKey(),
            'order_item_id' => $item->getKey(),
            'subscription_id' => $subscription->getKey(),
            'kind' => 'vps',
            'resources' => [],
        ]);

        return $subscription;
    }
}
