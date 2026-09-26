<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Services\PlanCapacity;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Orders\Infrastructure\Models\OrderItem;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
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

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $arguments
     * @return list<array{accepted: bool, code: ?string, refusals: list<string>}>
     */
    private function race(array $arguments): array
    {
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

    private function boughtSubscription(Plan $plan): Subscription
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $order = Order::factory()->paid()->create(['customer_id' => $customer->getKey(), 'currency' => 'KWD']);

        /** @var OrderItem $item */
        $item = OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'plan_id' => $plan->getKey(),
            'kind' => 'plan',
            'name' => $plan->slug,
            'billing_period' => BillingPeriod::Monthly,
            'quantity' => 1,
            'unit_recurring_minor' => 9_000,
            'unit_setup_minor' => 0,
            'total_minor' => 9_000,
        ]);

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::now()->subDays(10))
            ->create([
                'customer_id' => $customer->getKey(),
                'order_id' => $order->getKey(),
                'plan_id' => $plan->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => 9_000,
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
