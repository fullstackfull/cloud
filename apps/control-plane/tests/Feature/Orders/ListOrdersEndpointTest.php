<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/orders.
 *
 * A list endpoint is where a tenancy mistake is quietest: nobody gets a 500 and
 * nothing looks wrong, there are simply more rows in the response than there
 * should be. So the tests here count.
 */
final class ListOrdersEndpointTest extends OrdersApiTestCase
{
    #[Test]
    public function a_customer_sees_their_own_orders_newest_first(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $older = Order::factory()->create([
            'customer_id' => $customer->id,
            'created_at' => now()->subDay(),
        ]);
        $newer = Order::factory()->create([
            'customer_id' => $customer->id,
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.total', 2);
    }

    #[Test]
    public function another_customers_orders_are_not_in_the_list(): void
    {
        [$mine, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        Order::factory()->create(['customer_id' => $mine->id]);
        Order::factory()->count(3)->create(['customer_id' => $theirs->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1);

        // Not merely "fewer rows": none of the other account's ids appear
        // anywhere in the payload.
        $body = json_encode($response->json(), JSON_THROW_ON_ERROR);
        foreach (Order::query()->where('customer_id', $theirs->id)->pluck('id') as $foreignId) {
            $this->assertStringNotContainsString((string) $foreignId, $body);
        }
    }

    #[Test]
    public function the_page_size_is_bounded_however_much_is_asked_for(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        Order::factory()->count(30)->create(['customer_id' => $customer->id]);

        // A caller must not be able to turn one request into a full table scan
        // of their history — or, on a larger account, of a million rows.
        $this->actingAs($user)
            ->getJson('/api/v1/orders?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.max_per_page', 100)
            ->assertJsonCount(30, 'data');

        $this->actingAs($user)
            ->getJson('/api/v1/orders?per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.last_page', 6)
            ->assertJsonCount(5, 'data');
    }

    #[Test]
    public function paging_walks_the_whole_history_without_repeating_a_row(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        Order::factory()->count(7)->create([
            'customer_id' => $customer->id,
            // Every order created in the same instant, so the ordering has to
            // fall back to the ULID tie-break rather than to insertion luck.
            'created_at' => now(),
        ]);

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $ids = $this->actingAs($user)
                ->getJson('/api/v1/orders?per_page=3&page='.$page)
                ->assertOk()
                ->json('data.*.id');

            $seen = array_merge($seen, $ids);
        }

        $this->assertCount(7, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)));
    }

    #[Test]
    public function the_list_can_be_filtered_by_status(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::PendingPayment]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Cancelled]);

        $this->actingAs($user)
            ->getJson('/api/v1/orders?status='.OrderStatus::Cancelled->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', OrderStatus::Cancelled->value);
    }

    #[Test]
    public function an_unknown_status_or_page_size_is_a_422_naming_the_field(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->getJson('/api/v1/orders?status=nearly_paid&per_page=lots')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['status', 'per_page']]]]);
    }

    #[Test]
    public function a_member_without_billing_visibility_cannot_read_the_order_history(): void
    {
        [$customer, $user] = $this->accountWithOwner(CustomerRole::Member);

        Order::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->getJson('/api/v1/orders')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');
    }

    #[Test]
    public function the_list_carries_nothing_internal(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        Order::factory()->create([
            'customer_id' => $customer->id,
            'idempotency_key' => 'a-replayable-key',
        ]);

        $row = $this->actingAs($user)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->json('data.0');

        foreach (['customer_id', 'placed_by_user_id', 'idempotency_key', 'coupon_id', 'billing_snapshot'] as $field) {
            $this->assertArrayNotHasKey($field, $row, sprintf('%s must not be exposed to a customer.', $field));
        }

        $this->assertStringNotContainsString('a-replayable-key', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
