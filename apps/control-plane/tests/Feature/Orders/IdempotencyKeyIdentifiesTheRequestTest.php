<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An idempotency key has to identify the request, not just the attempt.
 *
 * The key answers "have I seen this before?". On its own it does not answer
 * "was it the same thing?", and the gap is where a real failure lives: a client
 * that reuses a key for a genuinely different basket — a retry rebuilt from a
 * stale form, a queue replaying a message after the cart changed, a library
 * deriving the key from the session rather than the payload — was silently
 * handed the first order. The customer got a confirmation for something they had
 * not just bought, and nothing anywhere recorded that the two requests disagreed.
 */
final class IdempotencyKeyIdentifiesTheRequestTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
    }

    private function plan(int $monthlyMinor): Plan
    {
        $product = Product::factory()->create(['is_active' => true, 'is_public' => true]);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'is_public' => true,
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $monthlyMinor,
            'is_active' => true,
        ]);

        return $plan;
    }

    private function checkout(Plan $plan, int $quantity, string $key): CheckoutRequest
    {
        return new CheckoutRequest(
            lines: [new CheckoutLine($plan->id, $quantity)],
            billingPeriod: BillingPeriod::Monthly,
            idempotencyKey: $key,
        );
    }

    #[Test]
    public function the_same_basket_under_the_same_key_returns_the_original_order(): void
    {
        $plan = $this->plan(9000);
        $place = app(PlaceOrder::class);

        $first = $place->execute($this->customer, $this->checkout($plan, 1, 'key-1'));
        $second = $place->execute($this->customer, $this->checkout($plan, 1, 'key-1'));

        // The whole point: a double-clicked buy button produces one order.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function the_order_of_the_lines_does_not_make_it_a_different_basket(): void
    {
        $first = $this->plan(9000);
        $second = $this->plan(5000);
        $place = app(PlaceOrder::class);

        $a = $place->execute($this->customer, new CheckoutRequest(
            lines: [new CheckoutLine($first->id, 1), new CheckoutLine($second->id, 2)],
            billingPeriod: BillingPeriod::Monthly,
            idempotencyKey: 'key-2',
        ));

        $b = $place->execute($this->customer, new CheckoutRequest(
            lines: [new CheckoutLine($second->id, 2), new CheckoutLine($first->id, 1)],
            billingPeriod: BillingPeriod::Monthly,
            idempotencyKey: 'key-2',
        ));

        $this->assertSame($a->id, $b->id);
    }

    #[Test]
    public function a_different_basket_under_the_same_key_is_refused(): void
    {
        $plan = $this->plan(9000);
        $place = app(PlaceOrder::class);

        $original = $place->execute($this->customer, $this->checkout($plan, 1, 'key-3'));

        try {
            $place->execute($this->customer, $this->checkout($plan, 5, 'key-3'));
            $this->fail('A different basket was accepted under a used key.');
        } catch (CheckoutRejectedException $e) {
            $this->assertSame('checkout.idempotency_key_reused', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(['idempotency_key' => 'key-3'], $e->context());
        }

        // Refused rather than either silently replayed or placed twice.
        $this->assertSame(1, Order::query()->count());
        $this->assertSame($original->id, Order::query()->sole()->id);
    }

    #[Test]
    public function a_different_plan_under_the_same_key_is_refused(): void
    {
        $one = $this->plan(9000);
        $two = $this->plan(9000);
        $place = app(PlaceOrder::class);

        $place->execute($this->customer, $this->checkout($one, 1, 'key-4'));

        // Same quantity, same price, same period — a hash of the amount alone
        // would call these identical, and they are not the same purchase.
        $this->expectException(CheckoutRejectedException::class);
        $place->execute($this->customer, $this->checkout($two, 1, 'key-4'));
    }

    #[Test]
    public function an_order_written_before_the_fingerprint_existed_still_replays(): void
    {
        $plan = $this->plan(9000);
        $place = app(PlaceOrder::class);

        $original = $place->execute($this->customer, $this->checkout($plan, 1, 'key-5'));

        // Rows that predate the column have no fingerprint. They must keep
        // behaving exactly as they did rather than start answering 409 to
        // clients that never changed.
        $original->forceFill(['request_fingerprint' => null])->save();

        $replay = $place->execute($this->customer, $this->checkout($plan, 9, 'key-5'));

        $this->assertSame($original->id, $replay->id);
    }

    #[Test]
    public function two_customers_may_use_the_same_key(): void
    {
        $plan = $this->plan(9000);
        $other = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $place = app(PlaceOrder::class);

        $mine = $place->execute($this->customer, $this->checkout($plan, 1, 'shared-key'));
        $theirs = $place->execute($other, $this->checkout($plan, 3, 'shared-key'));

        // The key is scoped to the customer. One customer's choice of key must
        // never be able to collide with — or reveal anything about — another's.
        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame(2, Order::query()->count());
    }
}
