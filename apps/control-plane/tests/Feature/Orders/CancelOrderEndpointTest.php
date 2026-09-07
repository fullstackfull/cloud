<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /api/v1/orders/{order}/cancel.
 *
 * Cancellation is the cheap half of a pair whose expensive half is a refund.
 * The line between them is the only thing this endpoint really has to get
 * right: everything the customer has not paid for, and nothing they have.
 */
final class CancelOrderEndpointTest extends OrdersApiTestCase
{
    #[Test]
    public function an_unpaid_order_is_cancelled(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::PendingPayment,
            'placed_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$order->id.'/cancel', ['reason' => 'changed my mind'])
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.is_cancellable', false);

        $order->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);

        // Recorded rather than merely applied: an order that ends up cancelled
        // is useless to support without who did it and why.
        $transition = DB::table('order_transitions')->where('order_id', $order->id)->latest('created_at')->first();

        $this->assertNotNull($transition);
        $this->assertSame(OrderStatus::Cancelled->value, $transition->to_status);
        $this->assertSame('user', $transition->actor_type);
        $this->assertSame($user->id, $transition->actor_user_id);
        $this->assertSame('changed my mind', $transition->reason);
    }

    #[Test]
    public function a_draft_and_a_failed_payment_are_both_cancellable(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        foreach ([OrderStatus::Draft, OrderStatus::PaymentFailed] as $status) {
            $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => $status]);

            $this->actingAs($user)
                ->postJson('/api/v1/orders/'.$order->id.'/cancel')
                ->assertOk()
                ->assertJsonPath('data.status', OrderStatus::Cancelled->value);
        }
    }

    #[Test]
    public function cancelling_twice_converges_instead_of_failing(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::PendingPayment,
        ]);

        $this->actingAs($user)->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertOk();

        // A retried request over a flaky connection must not be punished for
        // succeeding the first time.
        $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$order->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        // And it must not manufacture a second audit row claiming something
        // happened twice.
        $this->assertSame(
            1,
            DB::table('order_transitions')
                ->where('order_id', $order->id)
                ->where('to_status', OrderStatus::Cancelled->value)
                ->count(),
        );
    }

    #[Test]
    public function a_paid_order_is_refused_because_cancelling_it_would_be_a_refund(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $order = Order::factory()->paid()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$order->id.'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order.already_paid');

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    #[Test]
    public function an_active_order_that_has_been_provisioned_is_refused_too(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Active,
            'paid_at' => now(),
            'completed_at' => now(),
        ]);

        // The customer has paid and the hardware exists. Whatever the right
        // operation is, it is not one that quietly marks the order cancelled.
        $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$order->id.'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order.already_paid');

        $this->assertSame(OrderStatus::Active, $order->fresh()->status);
    }

    #[Test]
    public function a_refunded_order_has_nothing_left_to_cancel(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Refunded,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$order->id.'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order.not_cancellable');
    }

    #[Test]
    public function an_order_under_review_before_capture_is_not_described_as_paid(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // PENDING_PAYMENT -> MANUAL_REVIEW is a risk check diverting an order
        // before anybody's card is touched, so paid_at is null and no money has
        // moved. OrderStatus::isPaid() answers true for the status all the
        // same, and a refusal derived from it tells this customer their order
        // has been paid and that cancelling it would be a refund — a statement
        // about their money that is false, and one that sends them to request a
        // refund of a payment that never happened.
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::ManualReview,
            'placed_at' => now(),
            'paid_at' => null,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$order->id.'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order.not_cancellable');

        // Still refused — the account under review does not close its own case.
        $this->assertSame(OrderStatus::ManualReview, $order->fresh()->status);
    }

    #[Test]
    public function the_paid_flag_reports_capture_and_not_the_status_name(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $underReview = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::ManualReview,
            'paid_at' => null,
        ]);
        $captured = Order::factory()->paid()->create(['customer_id' => $customer->id]);

        // The flag and paid_at are the same fact, so a payload that says
        // "is_paid": true beside "paid_at": null is contradicting itself.
        $this->actingAs($user)
            ->getJson('/api/v1/orders/'.$underReview->id)
            ->assertOk()
            ->assertJsonPath('data.is_paid', false)
            ->assertJsonPath('data.paid_at', null);

        $this->actingAs($user)
            ->getJson('/api/v1/orders/'.$captured->id)
            ->assertOk()
            ->assertJsonPath('data.is_paid', true);
    }

    #[Test]
    public function another_customers_order_is_a_404_and_not_a_403(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = Order::factory()->create([
            'customer_id' => $theirs->id,
            'status' => OrderStatus::PendingPayment,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$foreign->id.'/cancel')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        // And it is still standing.
        $this->assertSame(OrderStatus::PendingPayment, $foreign->fresh()->status);
    }

    #[Test]
    public function a_real_foreign_id_and_an_invented_one_answer_identically(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = Order::factory()->create(['customer_id' => $theirs->id]);

        $real = $this->actingAs($user)->postJson('/api/v1/orders/'.$foreign->id.'/cancel');
        $invented = $this->actingAs($user)->postJson('/api/v1/orders/01jzzzzzzzzzzzzzzzzzzzzzzz/cancel');

        $this->assertSame($real->status(), $invented->status());
        $this->assertSame($real->json('error.code'), $invented->json('error.code'));
        $this->assertSame($real->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function an_over_long_reason_is_a_422_naming_the_field(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::PendingPayment,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$order->id.'/cancel', ['reason' => str_repeat('x', 256)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['reason']]]]);

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    #[Test]
    public function a_member_without_billing_authority_cannot_cancel(): void
    {
        [$customer, $user] = $this->accountWithOwner(CustomerRole::Member);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::PendingPayment,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$order->id.'/cancel')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    #[Test]
    public function the_response_carries_nothing_internal(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::PendingPayment,
            'idempotency_key' => 'a-replayable-key',
        ]);

        $body = $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$order->id.'/cancel')
            ->assertOk()
            ->json('data');

        foreach (['customer_id', 'placed_by_user_id', 'idempotency_key', 'coupon_id', 'billing_snapshot'] as $field) {
            $this->assertArrayNotHasKey($field, $body, sprintf('%s must not be exposed to a customer.', $field));
        }
    }
}
