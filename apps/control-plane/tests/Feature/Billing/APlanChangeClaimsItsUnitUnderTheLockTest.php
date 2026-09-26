<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Orders\Application\Services\PlanCapacity;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * Plan changes racing for the last unit of a plan take it once.
 *
 * The quote reads the plan's counts as a courtesy, before any lock. What stops
 * an oversell is ApplyPlanChange::claimTheUnit(): the plan row is locked
 * through PlanCapacity::lock() - the lock every checkout takes - and the counts
 * are read again under it, inside the transaction that moves the subscription.
 * Without it, every racer's quote reads the same free unit and every racer
 * moves onto the plan.
 *
 * The racers are separate PHP processes released together by an advisory-lock
 * barrier, because two statements on one connection can never race. So the
 * fixtures are committed, and LeavesNothingCommitted empties every table after.
 */
final class APlanChangeClaimsItsUnitUnderTheLockTest extends TestCase
{
    use LeavesNothingCommitted;
    use RefreshDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->emptyEveryTable();

        parent::tearDown();
    }

    #[Test]
    public function four_plan_changes_onto_the_last_unit_move_exactly_one_subscription(): void
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $small = $this->plan($product, 'small', 9_000, null);
        $large = $this->plan($product, 'large', 18_000, 1);

        $subscriptions = array_map(fn (): Subscription => $this->boughtSubscription($small), range(1, 4));
        $price = PlanPrice::query()->where('plan_id', $large->getKey())->sole();

        $results = $this->race(array_map(
            static fn (Subscription $s): array => [(string) $s->getKey(), (string) $large->getKey(), (string) $price->getKey()],
            $subscriptions,
        ));

        $accepted = array_values(array_filter($results, static fn (array $r): bool => $r['accepted']));
        $refused = array_values(array_filter($results, static fn (array $r): bool => ! $r['accepted']));

        $this->assertCount(4, $results, 'Every racer must have reported an outcome.');
        $this->assertCount(1, $accepted, 'One unit left: exactly one subscription may move onto it.');

        // Positive control: turned away by the capacity rule, not by a crash,
        // a deadlock or a timeout that would make the count right by accident.
        foreach ($refused as $result) {
            $this->assertSame('subscription.plan_change_refused', $result['code']);
            $this->assertSame(['out_of_stock'], $result['refusals']);
        }

        $this->assertSame(1, Subscription::query()->where('plan_id', $large->getKey())->count());
        $this->assertSame(1, app(PlanCapacity::class)->claimed((string) $large->getKey()));
    }

    #[Test]
    public function two_changes_asking_two_units_each_of_three_left_move_one_subscription(): void
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $small = $this->plan($product, 'small', 9_000, null);
        $big = $this->plan($product, 'big', 18_000, 3);

        // Two units each: the claim must ask for two, not one.
        $subscriptions = array_map(fn (): Subscription => $this->boughtSubscription($small, quantity: 2), range(1, 2));
        $price = PlanPrice::query()->where('plan_id', $big->getKey())->sole();

        $results = $this->race(array_map(
            static fn (Subscription $s): array => [(string) $s->getKey(), (string) $big->getKey(), (string) $price->getKey()],
            $subscriptions,
        ));

        $accepted = array_values(array_filter($results, static fn (array $r): bool => $r['accepted']));
        $refused = array_values(array_filter($results, static fn (array $r): bool => ! $r['accepted']));

        $this->assertCount(1, $accepted, 'Three units left, two asked twice: one change fits.');
        $this->assertSame(['out_of_stock'], $refused[0]['refusals'] ?? null);
        $this->assertSame(2, app(PlanCapacity::class)->claimed((string) $big->getKey()));
    }

    #[Test]
    public function two_subscriptions_of_one_order_downgrading_at_once_draw_its_money_once(): void
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $large = $this->plan($product, 'large', 90_000, null);
        // Two different targets, so the plan-row lock cannot serialise them:
        // only the lock on the order they share can.
        $targets = [$this->plan($product, 'small-a', 9_000, null), $this->plan($product, 'small-b', 9_000, null)];

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();
        app(WalletLedger::class)->walletFor($customer, 'KWD');

        $order = Order::factory()->paid()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);
        $subscriptions = [
            $this->boughtSubscription($large, customer: $customer, order: $order, unit: 90_000),
            $this->boughtSubscription($large, customer: $customer, order: $order, unit: 90_000),
        ];

        // Paid 180.000, then 150.000 refunded: the order kept 30.000.
        self::captured(Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(),
            'order_id' => $order->getKey(),
            'subtotal_minor' => 180_000,
            'total_minor' => 180_000,
            'amount_paid_minor' => 180_000,
            'amount_refunded_minor' => 150_000,
        ]));

        /*
         * Made deterministic rather than left to timing. Before the racers are
         * released, a second connection locks both target plan rows, so each
         * racer gets as far as the plan lock and stops. Without the order lock
         * both have read the order's pool by then and each takes it in full;
         * with it, the second is still queued on the order row. The hold is
         * released once both are waiting on a row lock.
         */
        $hold = [(string) $targets[0]->getKey(), (string) $targets[1]->getKey()];

        $results = $this->race(holdingPlans: $hold, arguments: array_map(
            static fn (int $i): array => [
                (string) $subscriptions[$i]->getKey(),
                (string) $targets[$i]->getKey(),
                (string) PlanPrice::query()->where('plan_id', $targets[$i]->getKey())->sole()->getKey(),
                (string) $user->getKey(),
            ],
            [0, 1],
        ));

        $this->assertSame([true, true], array_column($results, 'accepted'), json_encode($results));

        $credited = (int) WalletTransaction::query()->where('kind', 'adjustment')->sum('amount_minor');
        $this->assertSame(30_000, $credited, 'Two siblings together may take back what the order kept, and no more.');
    }

    /**
     * @param  list<list<string>>  $arguments
     * @param  list<string>  $holdingPlans  plan rows a second connection holds locked until every racer waits on a row lock
     * @return list<array{accepted: bool, code: ?string, refusals: list<string>}>
     */
    private function race(array $arguments, array $holdingPlans = []): array
    {
        if ($holdingPlans !== []) {
            config(['database.connections.pgsql_plan_hold' => config('database.connections.'.config('database.default'))]);
            DB::connection('pgsql_plan_hold')->beginTransaction();

            foreach ($holdingPlans as $planId) {
                DB::connection('pgsql_plan_hold')->table('plans')->where('id', $planId)->lockForUpdate()->first();
            }
        }

        DB::select('SELECT pg_advisory_lock(424243)');

        /** @var list<Process> $processes */
        $processes = [];

        foreach ($arguments as $argv) {
            $process = new Process(
                ['php', __DIR__.'/plan_change_racer.php', ...$argv],
                base_path(),
                ['APP_ENV' => 'testing'],
                null,
                60.0,
            );

            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 45.0;

        while ((int) DB::scalar("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND objid = 424243") < count($arguments)) {
            if (microtime(true) > $deadline) {
                foreach ($processes as $process) {
                    $process->stop(0);
                }

                DB::select('SELECT pg_advisory_unlock(424243)');
                $this->fail('The racers never all reached the barrier, so nothing was raced.');
            }

            usleep(20_000);
        }

        DB::select('SELECT pg_advisory_unlock(424243)');

        if ($holdingPlans !== []) {
            $deadline = microtime(true) + 45.0;

            // Every racer is now stopped on a row lock: the held plan row, or
            // (with the order lock) the order row another racer holds.
            while ((int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND wait_event IN ('transactionid', 'tuple')") < count($arguments)) {
                if (microtime(true) > $deadline) {
                    DB::connection('pgsql_plan_hold')->rollBack();
                    $this->fail('The racers never reached the held plan rows.');
                }

                usleep(20_000);
            }

            DB::connection('pgsql_plan_hold')->rollBack();
            DB::purge('pgsql_plan_hold');
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            $line = trim($process->getOutput());
            $this->assertNotSame('', $line, 'A racer produced no verdict: '.$process->getErrorOutput());

            /** @var array{accepted: bool, code: ?string, refusals: list<string>} $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $results[] = $decoded;
        }

        return $results;
    }

    private function plan(Product $product, string $slug, int $minor, ?int $stockLimit): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => $slug,
            'resources' => [],
            'is_active' => true,
            'is_public' => true,
            'stock_limit' => $stockLimit,
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

    private function boughtSubscription(
        Plan $plan,
        int $quantity = 1,
        ?Customer $customer = null,
        ?Order $order = null,
        int $unit = 9_000,
    ): Subscription {
        $customer ??= Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $order ??= Order::factory()->paid()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);

        /** @var OrderItem $item */
        $item = OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'plan_id' => $plan->getKey(),
            'kind' => 'plan',
            'name' => $plan->slug,
            'billing_period' => BillingPeriod::Monthly,
            'quantity' => $quantity,
            'unit_recurring_minor' => $unit,
            'unit_setup_minor' => 0,
            'total_minor' => $unit * $quantity,
        ]);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::now()->subDays(20))
            ->create([
                'customer_id' => $customer->getKey(),
                'order_id' => $order->getKey(),
                'plan_id' => $plan->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => $unit * $quantity,
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

    /**
     * A paid invoice is paid by a capture: every payment applied to an invoice
     * is a transactions row (SettleInvoice's invariant), and what a downgrade
     * credit may draw on is read from those rows (WhatAnInvoiceStillHolds,
     * O-2). A fixture that only states amount_paid_minor describes money that
     * never arrived.
     */
    private static function captured(Invoice $invoice): Invoice
    {
        if ($invoice->amount_paid_minor > 0) {
            Transaction::factory()->create([
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->getKey(),
                'amount_minor' => $invoice->amount_paid_minor,
                'currency' => $invoice->currency,
            ]);
        }

        return $invoice;
    }
}
