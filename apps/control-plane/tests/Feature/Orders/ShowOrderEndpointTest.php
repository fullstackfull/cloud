<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/orders/{order}.
 */
final class ShowOrderEndpointTest extends OrdersApiTestCase
{
    #[Test]
    public function an_order_comes_back_with_its_lines(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $plan = $this->publishedPlan(monthlyMinor: 9000);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency' => 'KWD',
            'subtotal_minor' => 18000,
            'tax_minor' => 0,
            'total_minor' => 18000,
        ]);

        $order->items()->create([
            'plan_id' => $plan->id,
            'kind' => 'plan',
            'name' => 'CX-2',
            'billing_period' => BillingPeriod::Monthly,
            'resources_snapshot' => ['vcpu' => 2],
            'quantity' => 2,
            'unit_recurring_minor' => 9000,
            'unit_setup_minor' => 0,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => 18000,
            'tax_rate' => '0',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.total.minor_units', 18000)
            ->assertJsonPath('data.total.amount', '18.000')
            ->assertJsonPath('data.total.currency', 'KWD')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.name', 'CX-2')
            // The line is priced in the order's currency, not in one of its own.
            ->assertJsonPath('data.items.0.total.currency', 'KWD')
            ->assertJsonPath('data.items.0.total.amount', '18.000');
    }

    #[Test]
    public function another_customers_order_is_a_404_and_not_a_403(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = Order::factory()->create(['customer_id' => $theirs->id]);

        /*
         * 404, deliberately. A 403 would confirm that the id names a real
         * order, which on ULIDs is the difference between "you cannot read
         * this" and "this exists" — and the second is an enumeration oracle.
         */
        $this->actingAs($user)
            ->getJson('/api/v1/orders/'.$foreign->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');
    }

    #[Test]
    public function a_real_foreign_id_and_an_invented_one_answer_identically(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = Order::factory()->create(['customer_id' => $theirs->id]);

        $real = $this->actingAs($user)->getJson('/api/v1/orders/'.$foreign->id);
        $invented = $this->actingAs($user)->getJson('/api/v1/orders/01jzzzzzzzzzzzzzzzzzzzzzzz');

        // Byte for byte the same answer, or the pair of them is a way to sort
        // real order ids from invented ones.
        $this->assertSame($real->status(), $invented->status());
        $this->assertSame($real->json('error.code'), $invented->json('error.code'));
        $this->assertSame($real->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function naming_another_account_in_a_header_does_not_widen_the_lookup(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = Order::factory()->create(['customer_id' => $theirs->id]);

        // The acting account comes from membership, not from what the caller
        // would like it to be.
        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $theirs->id)
            ->getJson('/api/v1/orders/'.$foreign->id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'tenancy.account_unavailable');
    }

    #[Test]
    public function a_user_who_belongs_to_both_accounts_still_sees_only_the_one_they_act_for(): void
    {
        [$mine, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        /*
         * The harder shape than a stranger's id: the caller is a genuine,
         * accepted owner of *both* accounts, so the tenancy middleware succeeds
         * whichever one the header names and every authorisation check passes.
         * The only thing standing between the two histories is that the query
         * hangs off the account the request resolved to. If the lookup were a
         * global fetch with a membership check bolted on afterwards, this is
         * the request that would sail through it.
         */
        $this->memberOf($theirs, user: $user);

        $ours = Order::factory()->create(['customer_id' => $mine->id]);
        $foreign = Order::factory()->create(['customer_id' => $theirs->id]);

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $mine->id)
            ->getJson('/api/v1/orders/'.$foreign->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $mine->id)
            ->getJson('/api/v1/orders/'.$ours->id)
            ->assertOk()
            ->assertJsonPath('data.id', $ours->id);

        // The list obeys the same boundary, and the header is what moves it.
        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $theirs->id)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $foreign->id);
    }

    #[Test]
    public function cancelling_another_accounts_order_while_belonging_to_both_is_refused(): void
    {
        [$mine, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $this->memberOf($theirs, user: $user);

        $foreign = Order::factory()->create([
            'customer_id' => $theirs->id,
            'status' => OrderStatus::PendingPayment,
        ]);

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $mine->id)
            ->postJson('/api/v1/orders/'.$foreign->id.'/cancel')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        // A write is the expensive half of getting this wrong: the order the
        // caller was not acting for is untouched.
        $this->assertSame(OrderStatus::PendingPayment, $foreign->fresh()->status);
    }

    #[Test]
    public function a_member_without_billing_visibility_is_refused_before_the_lookup(): void
    {
        [$customer, $user] = $this->accountWithOwner(CustomerRole::Member);

        $own = Order::factory()->create(['customer_id' => $customer->id]);

        // 403 for their own account's order and 403 for an id that does not
        // exist: the permission answer must not depend on whether the row is
        // real, or the refusal itself becomes the oracle.
        $refusedOwn = $this->actingAs($user)->getJson('/api/v1/orders/'.$own->id);
        $refusedInvented = $this->actingAs($user)->getJson('/api/v1/orders/01jzzzzzzzzzzzzzzzzzzzzzzz');

        $refusedOwn->assertStatus(403)->assertJsonPath('error.code', 'auth.forbidden');
        $this->assertSame($refusedOwn->status(), $refusedInvented->status());
        $this->assertSame($refusedOwn->json('error.code'), $refusedInvented->json('error.code'));
    }

    #[Test]
    public function the_response_carries_nothing_internal(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'idempotency_key' => 'a-replayable-key',
            'billing_snapshot' => ['tax_id' => 'KW-TAX-1', 'address_line1' => 'Somewhere'],
        ]);

        $body = $this->actingAs($user)
            ->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->json('data');

        foreach (['customer_id', 'placed_by_user_id', 'idempotency_key', 'coupon_id', 'billing_snapshot'] as $field) {
            $this->assertArrayNotHasKey($field, $body, sprintf('%s must not be exposed to a customer.', $field));
        }

        $encoded = json_encode($body, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('a-replayable-key', $encoded);
        $this->assertStringNotContainsString('KW-TAX-1', $encoded);
        $this->assertStringNotContainsString($customer->id, $encoded);
    }
}
