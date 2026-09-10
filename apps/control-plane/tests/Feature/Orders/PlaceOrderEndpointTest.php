<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Coupon;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /api/v1/orders — checkout.
 *
 * The endpoint's whole job is to accept a basket and nothing else. Most of what
 * is asserted here is therefore about what the request is not allowed to
 * influence: the price, the tax, the discount, and whose account is charged.
 */
final class PlaceOrderEndpointTest extends OrdersApiTestCase
{
    #[Test]
    public function a_customer_places_an_order_and_gets_it_back_priced(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan(monthlyMinor: 9000);

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'basket-first-attempt')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertCreated();

        $response
            ->assertJsonPath('data.status', OrderStatus::PendingPayment->value)
            ->assertJsonPath('data.currency', 'KWD')
            // Money is an object, never a bare number: KWD has three minor
            // digits and a client that assumes two prints 90.00 for 9.000.
            ->assertJsonPath('data.total.minor_units', 9000)
            ->assertJsonPath('data.total.currency', 'KWD')
            ->assertJsonPath('data.total.amount', '9.000')
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.items.0.unit_recurring.minor_units', 9000);

        $order = Order::query()->sole();

        // Placed against the acting account, and attributed to the user who
        // pressed the button.
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($user->id, $order->placed_by_user_id);
    }

    #[Test]
    public function repeating_a_submission_with_the_same_key_returns_the_first_order(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan(monthlyMinor: 9000);

        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'double-clicked-buy-button')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertCreated();

        $second = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'double-clicked-buy-button')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.number'), $second->json('data.number'));

        // The point of the whole mechanism: one purchase, not two.
        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function the_same_key_used_by_a_different_account_places_a_separate_order(): void
    {
        [, $mine] = $this->accountWithOwner();
        [, $theirs] = $this->accountWithOwner();
        $plan = $this->publishedPlan();

        // The key is scoped to the customer. Two unrelated accounts that happen
        // to generate the same key must not collide into one order — one of
        // them would otherwise be handed the other's purchase.
        $first = $this->actingAs($mine)
            ->withHeader('Idempotency-Key', 'checkout-0001')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertCreated();

        $second = $this->actingAs($theirs)
            ->withHeader('Idempotency-Key', 'checkout-0001')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertCreated();

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(2, Order::query()->count());
    }

    #[Test]
    public function a_checkout_without_an_idempotency_key_is_refused(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();

        $this->actingAs($user)
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertStatus(422)
            // Its own code, naming the header: the key is not a form field,
            // and a client that renders this as "correct the highlighted
            // fields" sends the customer looking for a box that is not there.
            ->assertJsonPath('error.code', 'request.idempotency_key_rejected')
            ->assertJsonPath('error.details.header', 'Idempotency-Key');

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function a_body_field_cannot_stand_in_for_the_idempotency_header(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();

        // One source for the key, and it is the header. Otherwise a retry that
        // sends the header and an original that sent the body would look like
        // two different purchases.
        $this->actingAs($user)
            ->postJson('/api/v1/orders', $this->basket($plan) + ['idempotency_key' => 'from-the-body'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'request.idempotency_key_rejected');

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function the_header_wins_over_a_body_field_of_the_same_name(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'the-real-key')
            ->postJson('/api/v1/orders', $this->basket($plan) + ['idempotency_key' => 'a-different-key'])
            ->assertCreated();

        $this->assertSame('the-real-key', Order::query()->sole()->idempotency_key);
    }

    #[Test]
    public function a_submitted_price_is_ignored_and_the_catalogue_decides(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan(monthlyMinor: 9000);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'i-set-my-own-price')
            ->postJson('/api/v1/orders', array_merge($this->basket($plan), [
                'total_minor' => 1,
                'subtotal_minor' => 1,
                'discount_minor' => 8999,
                'tax_minor' => 0,
                'currency' => 'JPY',
                'items' => [[
                    'plan_id' => $plan->id,
                    'quantity' => 1,
                    'unit_recurring_minor' => 1,
                    'total_minor' => 1,
                ]],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.total.minor_units', 9000)
            ->assertJsonPath('data.currency', 'KWD');

        $order = Order::query()->sole();
        $this->assertSame(9000, $order->total_minor);
        $this->assertSame(0, $order->discount_minor);
        $this->assertSame('KWD', $order->currency);
    }

    #[Test]
    public function a_submitted_customer_id_does_not_move_the_order_to_another_account(): void
    {
        [$mine, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();
        $plan = $this->publishedPlan();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'charge-somebody-else')
            ->postJson('/api/v1/orders', $this->basket($plan) + [
                'customer_id' => $theirs->id,
                'placed_by_user_id' => $theirs->id,
            ])
            ->assertCreated();

        $order = Order::query()->sole();

        $this->assertSame($mine->id, $order->customer_id);
        $this->assertSame(0, Order::query()->where('customer_id', $theirs->id)->count());
    }

    #[Test]
    public function a_coupon_is_applied_from_the_code_and_not_from_a_submitted_amount(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan(monthlyMinor: 10000);

        Coupon::query()->create([
            'code' => 'HALFOFF',
            'discount_type' => 'percentage',
            'percentage' => '0.500',
            'is_active' => true,
            'max_redemptions' => 5,
            'max_redemptions_per_customer' => 5,
            'redemption_count' => 0,
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'with-a-coupon')
            ->postJson('/api/v1/orders', $this->basket($plan) + [
                'coupon_code' => 'HALFOFF',
                // The size of the discount is the coupon's business.
                'discount_minor' => 9999,
            ])
            ->assertCreated()
            ->assertJsonPath('data.discount.minor_units', 5000)
            ->assertJsonPath('data.total.minor_units', 5000)
            ->assertJsonPath('data.coupon_code', 'HALFOFF');
    }

    #[Test]
    public function an_empty_or_malformed_basket_is_a_422_naming_the_fields(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'nothing-in-the-basket')
            ->postJson('/api/v1/orders', ['items' => [], 'billing_period' => 'fortnightly'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['items', 'billing_period']]]]);
    }

    #[Test]
    public function a_zero_or_negative_quantity_is_refused_before_anything_is_priced(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'minus-one-server-please')
            ->postJson('/api/v1/orders', [
                'items' => [['plan_id' => $plan->id, 'quantity' => -1]],
                'billing_period' => BillingPeriod::Monthly->value,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['items.0.quantity']]]]);

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function a_plan_that_does_not_exist_is_refused_with_a_checkout_code(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'a-plan-i-invented')
            ->postJson('/api/v1/orders', [
                'items' => [['plan_id' => '01jzzzzzzzzzzzzzzzzzzzzzzz', 'quantity' => 1]],
                'billing_period' => BillingPeriod::Monthly->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.plan_unavailable');
    }

    #[Test]
    public function a_suspended_account_cannot_place_an_order(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();

        $customer->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'suspended-but-shopping')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.account_not_purchasable');

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function a_member_without_billing_authority_cannot_spend_the_accounts_money(): void
    {
        [, $user] = $this->accountWithOwner(CustomerRole::Member);
        $plan = $this->publishedPlan();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'not-my-money')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function an_unlisted_plan_cannot_be_bought_through_the_endpoint(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();
        $plan->forceFill(['is_public' => false])->save();

        /*
         * GET /catalog/plans/{plan} answers 404 for this plan: it is not on
         * sale. The set of unlisted plans is exactly the set priced for
         * somebody else — a retired tier kept for legacy customers, an internal
         * or staff rate, a negotiated one — so a checkout that accepts the id
         * lets any customer who learns it buy at a price that was never offered
         * to them.
         */
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'a-plan-that-is-not-listed')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.plan_unavailable');

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function a_plan_whose_product_is_unlisted_cannot_be_bought_either(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();
        $plan->product->forceFill(['is_public' => false])->save();

        // A plan inherits its product's visibility, or withdrawing a product
        // from sale leaves every one of its plans reachable by direct id.
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'a-product-that-is-not-listed')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'checkout.plan_unavailable');

        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    public function the_same_plan_cannot_appear_twice_in_one_basket(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();
        $plan->forceFill(['per_customer_limit' => 1, 'stock_limit' => 1])->save();

        /*
         * Two lines of one each are not a richer basket than one line of two —
         * they are the same purchase split so that each half is measured
         * against a stock count neither half is in yet. One per customer
         * becomes one per line, and the plan sells twice.
         */
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'the-same-plan-twice')
            ->postJson('/api/v1/orders', $this->basket($plan, [
                ['plan_id' => $plan->id, 'quantity' => 1],
                ['plan_id' => $plan->id, 'quantity' => 1],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['items.1.plan_id']]]]);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, (int) DB::table('order_items')->sum('quantity'));
    }

    #[Test]
    public function the_response_carries_nothing_internal(): void
    {
        [, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan();

        $body = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'what-comes-back')
            ->postJson('/api/v1/orders', $this->basket($plan))
            ->assertCreated()
            ->json('data');

        foreach (['customer_id', 'placed_by_user_id', 'idempotency_key', 'coupon_id', 'billing_snapshot'] as $field) {
            $this->assertArrayNotHasKey($field, $body, sprintf('%s must not be exposed to a customer.', $field));
        }

        // The replay credential in particular: echoing it back would let anyone
        // who can read one response replay a purchase against the account.
        $this->assertStringNotContainsString('idempotency', json_encode($body, JSON_THROW_ON_ERROR));
    }
}
