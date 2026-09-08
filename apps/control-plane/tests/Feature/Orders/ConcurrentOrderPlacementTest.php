<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two checkouts in flight at the same instant, on two real connections.
 *
 * A double-clicked buy button is not a hypothetical: it is the single most
 * common way a customer produces two identical requests, and the two land on
 * two workers milliseconds apart. The unique index on
 * (customer_id, idempotency_key) is what stops that becoming two orders, two
 * invoices and two machines — but an index only turns the second write into an
 * exception, and what the platform does with that exception is the part a test
 * has to pin down.
 *
 * These tests do not use the default connection's test transaction for the rows
 * under examination. RefreshDatabase never commits, so a second connection
 * would see none of it, and two statements on one connection are serialised by
 * definition and can never race. Fixtures are therefore committed for real and
 * removed again in a finally block.
 */
final class ConcurrentOrderPlacementTest extends TestCase
{
    use RefreshDatabase;

    private const string WORKER_A = 'orders_a';

    private const string WORKER_B = 'orders_b';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::WORKER_A, self::WORKER_B] as $name) {
            config()->set('database.connections.'.$name, config('database.connections.pgsql'));
        }
    }

    /**
     * A customer and a sellable plan, committed on worker A so that both
     * connections can see them.
     *
     * @return array{0: Customer, 1: Plan}
     */
    private function committedFixtures(): array
    {
        $customer = Customer::factory()->make(['currency' => 'KWD', 'country' => 'KW']);
        $customer->setConnection(self::WORKER_A)->save();

        $product = Product::factory()->make();
        $product->setConnection(self::WORKER_A)->save();

        $plan = Plan::factory()->make(['product_id' => $product->id]);
        $plan->setConnection(self::WORKER_A)->save();

        $price = PlanPrice::factory()->make([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
            'setup_amount_minor' => 0,
        ]);
        $price->setConnection(self::WORKER_A)->save();

        return [$customer, $plan];
    }

    private function cleanUp(Customer $customer, Plan $plan): void
    {
        // Orders, items and transitions cascade from the customer.
        Customer::on(self::WORKER_A)->whereKey($customer->id)->forceDelete();
        PlanPrice::on(self::WORKER_A)->where('plan_id', $plan->id)->delete();
        Plan::on(self::WORKER_A)->whereKey($plan->id)->delete();
        Product::on(self::WORKER_A)->whereKey($plan->product_id)->delete();
    }

    private function request(Plan $plan, string $key): CheckoutRequest
    {
        return new CheckoutRequest(
            lines: [new CheckoutLine($plan->id, 1)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: null,
            idempotencyKey: $key,
        );
    }

    #[Test]
    public function the_loser_of_an_idempotency_race_is_handed_the_winners_order_rather_than_an_error(): void
    {
        [$customer, $plan] = $this->committedFixtures();
        $key = (string) Str::ulid();
        $winnerId = (string) Str::ulid();

        try {
            /*
             * The competitor commits inside the window that matters: after this
             * checkout has looked for an existing order under the key and found
             * none, and before its own insert reaches the index. Hooked to the
             * model's creating event because that is exactly where the window
             * is; there is no other way to hit it from a single-threaded test.
             */
            $fired = false;

            Order::creating(function () use (&$fired, $customer, $key, $winnerId): void {
                if ($fired) {
                    return;
                }

                $fired = true;

                DB::connection(self::WORKER_B)->table('orders')->insert([
                    'id' => $winnerId,
                    'customer_id' => $customer->id,
                    'number' => 'ORD-RACE-0001',
                    'status' => OrderStatus::PendingPayment->value,
                    'currency' => 'KWD',
                    'subtotal_minor' => 9_000,
                    'discount_minor' => 0,
                    'tax_minor' => 0,
                    'total_minor' => 9_000,
                    'idempotency_key' => $key,
                    'placed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $returned = app(PlaceOrder::class)->execute($customer, $this->request($plan, $key));

            // The customer gets an order, and it is the one that exists.
            $this->assertSame($winnerId, $returned->id);

            $rows = DB::connection(self::WORKER_A)->table('orders')
                ->where('customer_id', $customer->id)
                ->count();

            // One order. Two would mean two invoices and two machines for one
            // purchase.
            $this->assertSame(1, $rows);
        } finally {
            Order::flushEventListeners();
            $this->cleanUp($customer, $plan);
        }
    }

    #[Test]
    public function no_other_worker_can_see_a_committed_order_still_sitting_in_draft(): void
    {
        /*
         * An order is written as DRAFT and then transitioned, so that the state
         * machine validates the move and the transition table records how the
         * order got there. Both are worth keeping. What matters here is whether
         * the draft is ever *committed* — visible to another connection —
         * because everything else in the platform treats a draft order as one
         * the customer has not placed: it is hidden from the order list, it has
         * no payment to start, and the loser of the race above would be handed
         * one and told it is their order.
         *
         * The observation is made from a second connection at the moment each
         * transaction commits, which is precisely what another worker would
         * see.
         */
        [$customer, $plan] = $this->committedFixtures();
        $key = (string) Str::ulid();
        $previousDefault = DB::getDefaultConnection();

        /** @var list<string> $observed */
        $observed = [];

        try {
            // The action must run on a connection that really commits; the test
            // transaction on the default connection would hide every write.
            DB::setDefaultConnection(self::WORKER_A);

            Event::listen(function (TransactionCommitted $event) use (&$observed, $customer, $key): void {
                if ($event->connectionName !== self::WORKER_A) {
                    return;
                }

                $status = DB::connection(self::WORKER_B)->table('orders')
                    ->where('customer_id', $customer->id)
                    ->where('idempotency_key', $key)
                    ->value('status');

                if (is_string($status)) {
                    $observed[] = $status;
                }
            });

            $order = app(PlaceOrder::class)->execute($customer, $this->request($plan, $key));

            $this->assertSame(OrderStatus::PendingPayment, $order->status);
            $this->assertNotEmpty($observed, 'Nothing was observed; this test would pass without checking anything.');
            $this->assertNotContains(
                OrderStatus::Draft->value,
                $observed,
                'A committed order was visible to another worker as a draft: the write and the transition '
                .'that places it must commit together.',
            );
        } finally {
            DB::setDefaultConnection($previousDefault);
            $this->cleanUp($customer, $plan);
        }
    }
}
