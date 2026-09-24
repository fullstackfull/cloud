<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\CancelOrder;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Exceptions\CheckoutRejectedException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\Support\PlaceableEstate;
use Tests\TestCase;

/**
 * Finite capacity has to be claimed under a lock, not read and hoped for.
 *
 * ---------------------------------------------------------------------------
 * What was wrong
 * ---------------------------------------------------------------------------
 *
 * `OrderPricing::assertStock()` counts what the database holds and compares it
 * with the plan's limit. No lock is taken on anything, so every checkout in
 * flight reads the same remaining capacity and every one of them likes the
 * answer. The audit reproduced a plan with `stock_limit = 1` accepting eight
 * orders, and `per_customer_limit = 1` accepting six.
 *
 * Its own docblock said "This is a pre-check, not a reservation — the
 * authoritative claim happens when the paid order reserves capacity". There
 * was no such claim anywhere in the codebase. That sentence was the only thing
 * standing between the read and an oversell, and it was prose.
 *
 * The coupon path in the same action already does it properly:
 * `assertCouponHasUnspentCapacity()` takes `SELECT ... FOR UPDATE` on the
 * coupon row inside the transaction that writes the order. This is the same
 * shape applied to the plan.
 *
 * ---------------------------------------------------------------------------
 * Why these tests commit
 * ---------------------------------------------------------------------------
 *
 * A row lock cannot be exercised from one connection: two statements on one
 * connection are serialised by definition and can never race. So the fixtures
 * are committed for real, a second connection stands in for a second worker,
 * and `tearDown` truncates rather than rolling back. That is the same
 * arrangement CouponRedemptionConcurrencyTest uses, for the same reason.
 *
 * Every race test here carries a positive control: proof that the second
 * checkout actually reached the locking statement rather than reading around
 * it. A test that only asserts "one order exists" would pass just as happily
 * against code that never took a lock at all.
 */
final class FiniteCapacityIsClaimedNotGuessedTest extends TestCase
{
    use LeavesNothingCommitted;
    use PlaceableEstate;
    use RefreshDatabase;

    private const string SECOND_CHECKOUT = 'pgsql_capacity_b';

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.'.self::SECOND_CHECKOUT => config(
                'database.connections.'.config('database.default')
            ),
        ]);
    }

    /**
     * Nothing here rolls back, so everything here is put back by hand.
     *
     * The list this used to name — plans, products, customers, users, orders —
     * was right until checkout started needing an estate to place a plan on.
     * The cluster, the address pool and the image it now creates were
     * committed and never removed, and what failed was not this file: it was
     * an unrelated module two hundred tests later whose `sole()` found two
     * rows. LeavesNothingCommitted empties every table instead of guessing
     * which ones, which is what makes that class of failure impossible rather
     * than merely fixed.
     */
    protected function tearDown(): void
    {
        DB::statement("SET lock_timeout = '0'");
        DB::purge(self::SECOND_CHECKOUT);

        // Called rather than inherited: a class method wins over a trait's, so
        // declaring tearDown here silently replaces the trait's.
        $this->emptyEveryTable();

        parent::tearDown();
    }

    // ---- 1. the last unit is sold once ------------------------------------

    #[Test]
    public function eight_simultaneous_checkouts_for_one_remaining_unit_produce_one_order(): void
    {
        $plan = $this->plan(stockLimit: 1);
        $customers = array_map(fn (): string => (string) $this->customer()->getKey(), range(1, 8));

        $results = $this->race(array_map(
            fn (string $id): array => [$id, (string) $plan->getKey(), '-'],
            $customers,
        ));

        $accepted = array_values(array_filter($results, static fn (array $r): bool => $r['accepted']));
        $refused = array_values(array_filter($results, static fn (array $r): bool => ! $r['accepted']));

        $this->assertCount(8, $results, 'Every racer must have reported an outcome.');
        $this->assertCount(1, $accepted, 'A plan with one unit left may be sold exactly once.');
        $this->assertCount(7, $refused);

        // The positive control on the refusals: they were turned away by the
        // capacity rule, not by a crash, a deadlock or a lock timeout that
        // would make the count right for the wrong reason.
        foreach ($refused as $result) {
            $this->assertSame('checkout.out_of_stock', $result['code']);
        }

        $this->assertSame(1, Order::query()->count());
    }

    #[Test]
    public function a_checkout_waits_on_the_plan_row_the_other_checkout_holds(): void
    {
        /*
         * What the counts above rest on. It proves the claim is made with
         * SELECT ... FOR UPDATE on the plan row rather than by a read that
         * happens to be fast enough most of the time — and that the statement
         * which blocks is the claim itself, not some later insert that happens
         * to touch the same row through a foreign key.
         */
        $plan = $this->plan(stockLimit: 1);
        $customer = $this->customer();

        $second = DB::connection(self::SECOND_CHECKOUT);
        $second->beginTransaction();
        $second->select('SELECT id FROM plans WHERE id = ? FOR UPDATE', [$plan->id]);

        DB::statement("SET lock_timeout = '500ms'");

        try {
            $this->place($customer, $plan);
            $this->fail('A checkout claimed capacity while another connection held the plan row.');
        } catch (QueryException $e) {
            // 55P03 is lock_not_available: this checkout really did queue.
            $this->assertSame('55P03', $e->getCode());
            $this->assertStringContainsString(
                'for update',
                strtolower((string) $e->getSql()),
                'It must be the claim that blocks, not a later write.',
            );
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $second->rollBack();
        }

        $this->assertSame(0, Order::query()->count(), 'Nothing was written by the blocked checkout.');
    }

    // ---- 2. per-customer limits are claimed too ---------------------------

    #[Test]
    public function eight_simultaneous_checkouts_by_one_customer_against_a_limit_of_one_produce_one_order(): void
    {
        $plan = $this->plan(perCustomerLimit: 1);
        $customer = (string) $this->customer()->getKey();

        $results = $this->race(array_fill(0, 8, [$customer, (string) $plan->getKey(), '-']));

        $accepted = array_values(array_filter($results, static fn (array $r): bool => $r['accepted']));
        $refused = array_values(array_filter($results, static fn (array $r): bool => ! $r['accepted']));

        $this->assertCount(8, $results);
        $this->assertCount(1, $accepted, 'One per customer means one, however many tabs are open.');

        foreach ($refused as $result) {
            $this->assertSame('checkout.per_customer_limit', $result['code']);
        }
    }

    #[Test]
    public function a_per_customer_limit_is_per_customer_and_not_global(): void
    {
        $plan = $this->plan(perCustomerLimit: 1);

        $this->place($this->customer(), $plan);
        $this->place($this->customer(), $plan);

        $this->assertSame(
            2,
            Order::query()->count(),
            'One each is the whole point of a per-customer limit; global stock permits it.',
        );
    }

    #[Test]
    public function a_stock_limit_of_two_sells_exactly_two(): void
    {
        $plan = $this->plan(stockLimit: 2);

        $this->place($this->customer(), $plan);
        $this->place($this->customer(), $plan);

        $this->expectException(CheckoutRejectedException::class);
        $this->place($this->customer(), $plan);
    }

    // ---- 3. one basket cannot outrun itself -------------------------------

    #[Test]
    public function one_basket_naming_the_same_plan_twice_is_counted_once_in_aggregate(): void
    {
        $plan = $this->plan(stockLimit: 1);
        $customer = $this->customer();

        $this->expectException(CheckoutRejectedException::class);

        $this->placeLines($customer, [
            new CheckoutLine($plan->id, 1),
            new CheckoutLine($plan->id, 1),
        ]);
    }

    // ---- 4. release and replay --------------------------------------------

    #[Test]
    public function cancelling_a_held_order_puts_its_capacity_back(): void
    {
        $plan = $this->plan(stockLimit: 1);

        $order = $this->place($this->customer(), $plan);

        try {
            $this->place($this->customer(), $plan);
            $this->fail('The single unit was sold twice.');
        } catch (CheckoutRejectedException) {
            // Held by the unpaid order, which is the documented contract: a
            // customer at the card form has not lost the machine yet.
        }

        app(CancelOrder::class)->execute($order);

        // And now it is somebody else's to buy.
        $this->place($this->customer(), $plan);

        $this->assertSame(1, Order::query()->whereNot('status', 'cancelled')->count());
    }

    #[Test]
    public function replaying_one_idempotent_checkout_claims_capacity_once(): void
    {
        $plan = $this->plan(stockLimit: 1);
        $customer = $this->customer();
        $key = (string) Str::ulid();

        $first = $this->place($customer, $plan, $key);
        $again = $this->place($customer, $plan, $key);

        $this->assertSame($first->id, $again->id, 'A replay is the same order, not a second claim.');
        $this->assertSame(1, Order::query()->count());
    }

    // ---- 5. two plans, one basket, no deadlock ----------------------------

    #[Test]
    public function a_two_plan_basket_locks_in_a_stable_order_whichever_way_it_is_written(): void
    {
        /*
         * Two baskets naming the same two plans in opposite orders. Locked in
         * request order they can deadlock against each other — each holding
         * what the other wants next; locked in a stable order, sorted ids,
         * they queue instead.
         *
         * A single-threaded test cannot hold two baskets open at once, so the
         * property asserted is the one that makes the deadlock impossible:
         * **the sequence of plan rows a basket locks is the same whichever
         * way the basket was written.** That is read from the statements the
         * checkout actually issued, which is the only place the ordering is
         * observable — a test that watched only for a block would pass with
         * the sorting removed, because an unsorted basket still blocks, just
         * on its second row instead of its first.
         */
        foreach ([true, false] as $reversed) {
            // A fresh pair each time: these baskets are placed rather than
            // refused, and a plan held at one unit cannot be bought twice.
            [$low, $high] = $this->twoPlans();
            $written = $reversed ? [$high, $low] : [$low, $high];

            $locked = $this->lockedPlanRows(
                fn (): Order => $this->placeLines($this->customer(), [
                    new CheckoutLine($written[0]->id, 1),
                    new CheckoutLine($written[1]->id, 1),
                ]),
            );

            $this->assertSame(
                [$low->id, $high->id],
                $locked,
                'The basket locked its plan rows in the order it was written, not in a stable one: '
                .'two baskets naming the same plans the other way round can then deadlock.',
            );
        }

        [$low, $high] = $this->twoPlans();
        $customer = $this->customer();

        $second = DB::connection(self::SECOND_CHECKOUT);
        $second->beginTransaction();
        $second->select('SELECT id FROM plans WHERE id = ? FOR UPDATE', [$low->id]);

        DB::statement("SET lock_timeout = '500ms'");

        try {
            $this->placeLines($customer, [
                new CheckoutLine($high->id, 1),
                new CheckoutLine($low->id, 1),
            ]);
            $this->fail('A basket claimed capacity while the lower-id plan was locked elsewhere.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode());
        } finally {
            DB::statement("SET lock_timeout = '0'");
            $second->rollBack();
        }

        // With the lock released, the same basket completes.
        $order = $this->placeLines($customer, [
            new CheckoutLine($high->id, 1),
            new CheckoutLine($low->id, 1),
        ]);

        $this->assertCount(2, $order->items);
    }

    // ---- the race harness --------------------------------------------------

    /**
     * Runs one checkout per set of arguments, in its own process, all released
     * at the same instant.
     *
     * The barrier is an advisory lock and not a sleep. This connection holds
     * 424242 exclusively while every racer is started; each racer blocks
     * trying to take it in shared mode, so none of them can reach the checkout
     * until the single unlock below wakes all of them together. That is what
     * makes the reads overlap, which is the condition that oversells and which
     * no sequential loop can produce.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $arguments
     * @return list<array{accepted: bool, code: ?string, order: ?string}>
     */
    private function race(array $arguments): array
    {
        DB::select('SELECT pg_advisory_lock(424242)');

        /** @var list<Process> $processes */
        $processes = [];

        foreach ($arguments as $argv) {
            $process = new Process(
                ['php', __DIR__.'/place_order_racer.php', ...$argv],
                base_path(),
                ['APP_ENV' => 'testing'],
                null,
                60.0,
            );

            $process->start();
            $processes[] = $process;
        }

        /*
         * Every racer is now either blocked on the barrier or still booting.
         * Waiting for them all to be *blocked* is what makes the start
         * simultaneous, and the wait is on a condition rather than a duration:
         * pg_locks shows one row per session queued for the advisory lock.
         */
        $deadline = microtime(true) + 45.0;

        while ($this->racersWaiting() < count($arguments)) {
            if (microtime(true) > $deadline) {
                foreach ($processes as $process) {
                    $process->stop(0);
                }

                DB::select('SELECT pg_advisory_unlock(424242)');
                $this->fail('The racers never all reached the barrier, so nothing was raced.');
            }

            usleep(20_000);
        }

        DB::select('SELECT pg_advisory_unlock(424242)');

        $results = [];

        foreach ($processes as $process) {
            $process->wait();

            $line = trim($process->getOutput());

            $this->assertNotSame(
                '',
                $line,
                'A racer produced no verdict: '.$process->getErrorOutput(),
            );

            /** @var array{accepted: bool, code: ?string, order: ?string} $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $results[] = $decoded;
        }

        return $results;
    }

    /**
     * How many sessions are queued behind the barrier right now.
     */
    private function racersWaiting(): int
    {
        return (int) DB::scalar(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND objid = 424242"
        );
    }

    // ---- fixtures ---------------------------------------------------------

    private function plan(?int $stockLimit = null, ?int $perCustomerLimit = null): Plan
    {
        // Capacity is what this file is about, and a plan the platform cannot
        // place never gets as far as the capacity claim.
        $this->estateThatCanPlaceAVps();

        $product = Product::factory()->create();

        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'stock_limit' => $stockLimit,
            'per_customer_limit' => $perCustomerLimit,
        ]);

        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => 9_000,
            'setup_amount_minor' => 0,
        ]);

        return $plan->fresh(['prices', 'product']);
    }

    /**
     * Two plans of one unit each, returned lowest id first.
     *
     * @return array{0: Plan, 1: Plan}
     */
    private function twoPlans(): array
    {
        $a = $this->plan(stockLimit: 1);
        $b = $this->plan(stockLimit: 1);

        return $a->id < $b->id ? [$a, $b] : [$b, $a];
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
    }

    private function place(Customer $customer, Plan $plan, ?string $key = null): Order
    {
        return $this->placeLines($customer, [new CheckoutLine($plan->id, 1)], $key);
    }

    /**
     * @param  list<CheckoutLine>  $lines
     */
    /**
     * The plan rows a checkout locked, in the order it locked them.
     *
     * Read from the statements the connection issued rather than inferred
     * from what blocked: the ordering is a property of the code, and the only
     * way to see it is to watch the `SELECT ... FOR UPDATE`s go past. Bindings
     * are what carries the id, because the SQL itself is parameterised.
     *
     * @param  callable(): Order  $checkout
     * @return list<string>
     */
    private function lockedPlanRows(callable $checkout): array
    {
        /** @var list<string> $locked */
        $locked = [];

        DB::listen(function (QueryExecuted $query) use (&$locked): void {
            $sql = strtolower($query->sql);

            if (! str_contains($sql, 'from "plans"') || ! str_contains($sql, 'for update')) {
                return;
            }

            foreach ($query->bindings as $binding) {
                if (is_string($binding)) {
                    $locked[] = $binding;
                }
            }
        });

        try {
            $checkout();
        } finally {
            // Laravel has no public unlisten; a fresh connection drops the
            // listener with the old one rather than leaving it counting every
            // later test's statements.
            DB::purge();
        }

        return $locked;
    }

    private function placeLines(Customer $customer, array $lines, ?string $key = null): Order
    {
        return app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
            lines: $lines,
            billingPeriod: BillingPeriod::Monthly,
            couponCode: null,
            idempotencyKey: $key,
        ));
    }
}
