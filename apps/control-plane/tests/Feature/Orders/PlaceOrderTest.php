<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\Actions\TransitionOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PlaceOrderTest extends TestCase
{
    use RefreshDatabase;

    private PlaceOrder $placeOrder;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->placeOrder = app(PlaceOrder::class);
        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
    }

    private function plan(int $monthlyMinor = 9000, int $setupMinor = 0): Plan
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $monthlyMinor,
            'setup_amount_minor' => $setupMinor,
        ]);

        return $plan->fresh(['prices', 'product']);
    }

    private function request(Plan $plan, int $quantity = 1, ?string $key = null, ?string $coupon = null): CheckoutRequest
    {
        return new CheckoutRequest(
            lines: [new CheckoutLine($plan->id, $quantity)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: $coupon,
            idempotencyKey: $key,
        );
    }

    #[Test]
    public function an_order_is_priced_from_the_catalogue_and_recorded(): void
    {
        $plan = $this->plan(9000);

        $order = $this->placeOrder->execute($this->customer, $this->request($plan, 2));

        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertSame('KWD', $order->currency);
        $this->assertSame(18000, $order->total_minor);
        $this->assertSame(2, $order->items()->sole()->quantity);
    }

    #[Test]
    public function the_price_is_never_taken_from_the_request(): void
    {
        // The DTO carries only a plan id and a quantity, by construction.
        // A checkout that accepted a submitted amount would be a checkout
        // where the customer sets their own price, so this is asserted at the
        // type level rather than by trying to smuggle a price through.
        $properties = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(CheckoutLine::class))->getProperties(),
        );

        $this->assertSame(['planId', 'quantity'], $properties);
    }

    #[Test]
    public function the_order_is_written_as_draft_and_then_transitioned(): void
    {
        $order = $this->placeOrder->execute($this->customer, $this->request($this->plan()));

        // Writing PENDING_PAYMENT directly would bypass the state machine and
        // leave no record of how the order got there — which is exactly what
        // an operator needs when it later stalls.
        $transition = $order->transitions()->sole();
        $this->assertSame(OrderStatus::Draft, $transition->from_status);
        $this->assertSame(OrderStatus::PendingPayment, $transition->to_status);
        $this->assertSame('checkout submitted', $transition->reason);
    }

    #[Test]
    public function a_repeated_submission_with_the_same_key_returns_the_first_order(): void
    {
        $plan = $this->plan();

        $first = $this->placeOrder->execute($this->customer, $this->request($plan, key: 'checkout-abc'));
        $second = $this->placeOrder->execute($this->customer, $this->request($plan, key: 'checkout-abc'));

        // A double-clicked buy button must not become two servers and two bills.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function different_keys_produce_different_orders(): void
    {
        $plan = $this->plan();

        $this->placeOrder->execute($this->customer, $this->request($plan, key: 'first'));
        $this->placeOrder->execute($this->customer, $this->request($plan, key: 'second'));

        $this->assertSame(2, Order::query()->count());
    }

    #[Test]
    public function the_same_key_from_a_different_customer_is_a_separate_order(): void
    {
        $plan = $this->plan();
        $other = Customer::factory()->create(['currency' => 'KWD']);

        $mine = $this->placeOrder->execute($this->customer, $this->request($plan, key: 'shared-key'));
        $theirs = $this->placeOrder->execute($other, $this->request($plan, key: 'shared-key'));

        // Idempotency is scoped per customer; a key guessed or reused by
        // another account must never return someone else's order.
        $this->assertNotSame($mine->id, $theirs->id);
    }

    #[Test]
    public function a_suspended_account_cannot_place_an_order(): void
    {
        $suspended = Customer::factory()->suspended()->create(['currency' => 'KWD']);

        $this->expectException(CheckoutRejectedException::class);

        $this->placeOrder->execute($suspended, $this->request($this->plan()));
    }

    #[Test]
    public function an_empty_basket_is_refused(): void
    {
        $this->expectException(CheckoutRejectedException::class);

        $this->placeOrder->execute($this->customer, new CheckoutRequest(
            lines: [],
            billingPeriod: BillingPeriod::Monthly,
        ));
    }

    #[Test]
    public function an_inactive_plan_cannot_be_bought(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['is_active' => false])->save();

        try {
            $this->placeOrder->execute($this->customer, $this->request($plan));
            $this->fail('Expected the checkout to be rejected.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.plan_unavailable', $e->errorCode());
        }
    }

    #[Test]
    public function a_plan_not_priced_in_the_customers_currency_is_refused_rather_than_converted(): void
    {
        $plan = $this->plan();
        $usdCustomer = Customer::factory()->create(['currency' => 'USD']);

        try {
            $this->placeOrder->execute($usdCustomer, $this->request($plan));
            $this->fail('Expected the checkout to be rejected.');
        } catch (CheckoutRejectedException $e) {
            // Converting would invent a price nobody set.
            $this->assertSame('checkout.terms_unavailable', $e->errorCode());
        }
    }

    #[Test]
    public function a_zero_quantity_is_refused(): void
    {
        try {
            $this->placeOrder->execute($this->customer, $this->request($this->plan(), quantity: 0));
            $this->fail('Expected the checkout to be rejected.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.invalid_quantity', $e->errorCode());
        }
    }

    #[Test]
    public function stock_is_checked_at_order_time_not_only_at_provisioning(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['stock_limit' => 1])->save();

        $this->placeOrder->execute($this->customer, $this->request($plan));

        try {
            $this->placeOrder->execute($this->customer, $this->request($plan));
            $this->fail('Expected the second order to be refused.');
        } catch (CheckoutRejectedException $e) {
            // An order that fails at provisioning has already taken the
            // customer's money; one that is never accepted costs nothing.
            $this->assertSame('checkout.out_of_stock', $e->errorCode());
        }
    }

    #[Test]
    public function a_cancelled_order_releases_its_stock(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['stock_limit' => 1])->save();

        $first = $this->placeOrder->execute($this->customer, $this->request($plan));
        app(TransitionOrder::class)
            ->execute($first, OrderStatus::Cancelled, reason: 'customer changed their mind');

        // The stock was never consumed, so it must be sellable again.
        $second = $this->placeOrder->execute($this->customer, $this->request($plan));
        $this->assertNotSame($first->id, $second->id);
    }

    #[Test]
    public function a_per_customer_limit_is_enforced_independently_of_global_stock(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['per_customer_limit' => 1])->save();

        $this->placeOrder->execute($this->customer, $this->request($plan));

        try {
            $this->placeOrder->execute($this->customer, $this->request($plan));
            $this->fail('Expected the second order to be refused.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.per_customer_limit', $e->errorCode());
        }

        // Another customer is unaffected by the first one's limit.
        $other = Customer::factory()->create(['currency' => 'KWD']);
        $this->placeOrder->execute($other, $this->request($plan));

        $this->assertSame(2, Order::query()->count());
    }

    #[Test]
    public function a_limit_cannot_be_split_across_two_lines_of_one_basket(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['per_customer_limit' => 1, 'stock_limit' => 1])->save();

        /*
         * The limits are counted from what the database holds, and a basket is
         * not in the database while it is being priced. So the same plan named
         * twice used to be checked twice against the same untouched count: one
         * each, twice, both under a limit of one — and a plan held at a single
         * unit sold two.
         */
        try {
            $this->placeOrder->execute($this->customer, new CheckoutRequest(
                lines: [
                    new CheckoutLine($plan->id, 1),
                    new CheckoutLine($plan->id, 1),
                ],
                billingPeriod: BillingPeriod::Monthly,
                idempotencyKey: 'one-basket-two-lines',
            ));
            $this->fail('Expected the second line to be refused against the limit.');
        } catch (CheckoutRejectedException $e) {
            $this->assertContains($e->errorCode(), ['checkout.per_customer_limit', 'checkout.out_of_stock']);
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, (int) DB::table('order_items')->sum('quantity'));
    }

    #[Test]
    public function an_unlisted_plan_cannot_be_bought_by_id(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['is_public' => false])->save();

        // The catalogue 404s an unlisted plan. A checkout that still sells it
        // makes the visibility flag a display preference rather than a rule.
        try {
            $this->placeOrder->execute($this->customer, $this->request($plan->fresh(['prices', 'product'])));
            $this->fail('Expected an unlisted plan to be refused.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.plan_unavailable', $e->errorCode());
        }

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function a_plan_hanging_off_an_unlisted_product_cannot_be_bought_either(): void
    {
        $plan = $this->plan();
        $plan->product->forceFill(['is_public' => false])->save();

        try {
            $this->placeOrder->execute($this->customer, $this->request($plan->fresh(['prices', 'product'])));
            $this->fail('Expected a plan on an unlisted product to be refused.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.plan_unavailable', $e->errorCode());
        }

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function line_items_snapshot_what_was_sold(): void
    {
        $plan = $this->plan(9000, setupMinor: 5000);

        $order = $this->placeOrder->execute($this->customer, $this->request($plan));
        $item = $order->items()->sole();

        $this->assertSame(9000, $item->unit_recurring_minor);
        $this->assertSame(5000, $item->unit_setup_minor);
        $this->assertSame($plan->resources, $item->resources_snapshot);

        // Changing the catalogue afterwards must not rewrite the sale.
        $plan->prices()->update(['recurring_amount_minor' => 99000]);
        $this->assertSame(9000, $item->fresh()->unit_recurring_minor);
    }

    #[Test]
    public function the_billing_snapshot_is_taken_at_checkout(): void
    {
        $this->customer->forceFill(['city' => 'Kuwait City'])->save();

        $order = $this->placeOrder->execute($this->customer, $this->request($this->plan()));

        $this->assertSame('Kuwait City', $order->billing_snapshot['city']);

        $this->customer->forceFill(['city' => 'Salmiya'])->save();
        $this->assertSame('Kuwait City', $order->fresh()->billing_snapshot['city']);
    }

    #[Test]
    public function order_totals_equal_the_sum_of_the_line_totals(): void
    {
        $planA = $this->plan(9000);
        $planB = $this->plan(3250);

        $order = $this->placeOrder->execute($this->customer, new CheckoutRequest(
            lines: [new CheckoutLine($planA->id, 1), new CheckoutLine($planB->id, 3)],
            billingPeriod: BillingPeriod::Monthly,
        ));

        $sum = (int) $order->items()->sum('total_minor');
        $this->assertSame($order->total_minor, $sum);
    }
}
