<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\PlanPrice;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * A renewal (or an ending subscription's wind-up) and a downgrade, in two
 * processes, both complete while the upgrade's invoice is paid under them.
 *
 * The renewal found the open upgrade invoice by an unlocked read and then
 * locked it by its id; the wind-up did the same with its open invoices. An
 * invoice paid in between was then held as a paid invoice while they waited
 * for the subscription - which ApplyPlanChange holds while it waits for the
 * paid invoices a downgrade credit is drawn from. A deadlock (40P01), measured
 * by the round-four verifier's dl.sh (renew 4/4, cancel 3/3). They now lock
 * the invoice only while it is still open (the status is in the locking
 * statement), so what the lock order in WhatAnInvoiceStillHolds claims is
 * true: nothing holding a paid invoice waits for a subscription.
 *
 * Deterministic: a third connection holds the invoice and the subscription;
 * the renewal (or cancellation) is let reach its wait on the invoice, then the
 * downgrade its wait on the subscription; the third connection then pays the
 * invoice and commits, releasing both at once. Under the old locking the
 * renewal takes the now-paid invoice and asks for the subscription the
 * downgrade was just given, and the downgrade asks for that invoice.
 *
 * The fixtures are committed, and LeavesNothingCommitted empties every table
 * after.
 */
final class ARenewalAndAPlanChangeDoNotDeadlockTest extends TestCase
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
    public function a_renewal_and_a_downgrade_both_complete_when_the_upgrade_is_paid_under_them(): void
    {
        $fixture = $this->upgradeLeftOpen();

        $outcomes = $this->race($fixture, ['renew', $fixture['sub'], $fixture['period_end']->addSecond()->toIso8601String()]);

        $this->assertNoDeadlock($outcomes);
    }

    #[Test]
    public function a_cancellation_and_a_downgrade_both_complete_when_the_upgrade_is_paid_under_them(): void
    {
        $fixture = $this->upgradeLeftOpen();

        $outcomes = $this->race($fixture, ['cancel', $fixture['sub']]);

        $this->assertNoDeadlock($outcomes);
    }

    #[Test]
    public function a_renewal_does_not_lapse_an_upgrade_made_after_it_looked_while_an_operator_voids_it(): void
    {
        /*
         * The verifier's dl2: the renewal's unlocked read finds no open
         * upgrade; a plan change then commits one (Y); the renewal takes the
         * subscription; an operator's VoidInvoice(Y) holds Y and its
         * synchronous RestorePlanOnVoidedUpgrade waits for the subscription;
         * the renewal then lapsed Y - locking it after the subscription - and
         * the two deadlocked (also on the round-three base). The renewal now
         * lapses only an invoice it locked before the subscription; one that
         * appeared since ends this attempt without renewing, and the next
         * sweep takes it first.
         *
         * Two barriers inside the actions, advisory locks the test holds: the
         * renewal stops right after its unlocked read, the void right after
         * it has locked Y.
         */
        $fixture = $this->upgradeLeftOpen(withoutTheUpgrade: true);

        DB::select('SELECT pg_advisory_lock(?)', [self::RENEWAL_BARRIER]);
        DB::select('SELECT pg_advisory_lock(?)', [self::VOID_BARRIER]);

        try {
            $renew = $this->start(
                ['renew', $fixture['sub'], $fixture['period_end']->addSecond()->toIso8601String()],
                ['RACER_PAUSE_AFTER' => '/from "subscription_plan_changes"/', 'RACER_PAUSE_LOCK' => (string) self::RENEWAL_BARRIER],
            );
            $this->waitUntilWaitingOnAdvisory(1);

            // The upgrade, committed after the renewal looked.
            app(ApplyPlanChange::class)->execute(
                Subscription::query()->findOrFail($fixture['sub']),
                $fixture['large'],
                $fixture['large_price'],
                'raced-up-later',
                User::query()->findOrFail($fixture['user']),
            );
            /** @var Invoice $upgrade */
            $upgrade = Invoice::query()->where('subscription_id', $fixture['sub'])->where('status', InvoiceStatus::Open->value)->sole();

            $void = $this->start(
                ['void', (string) $upgrade->getKey()],
                ['RACER_PAUSE_AFTER' => '/from "invoices".*for update/i', 'RACER_PAUSE_LOCK' => (string) self::VOID_BARRIER],
            );
            $this->waitUntilWaitingOnAdvisory(2);

            // The renewal takes the subscription and meets the upgrade.
            DB::select('SELECT pg_advisory_unlock(?)', [self::RENEWAL_BARRIER]);
            $this->waitUntil(fn (): bool => ! $renew->isRunning() || $this->waiting() >= 1, 'The renewal neither finished nor waited.');

            // The void goes on to restore the plan, under the subscription's lock.
            DB::select('SELECT pg_advisory_unlock(?)', [self::VOID_BARRIER]);
        } finally {
            DB::select('SELECT pg_advisory_unlock_all()');
        }

        $outcomes = [$this->verdict($renew), $this->verdict($void)];
        $this->assertNoDeadlock($outcomes);

        // The upgrade was voided by the operator, not lapsed by the renewal,
        // and the renewal did not advance the period: the next sweep renews.
        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status);
        $this->assertTrue(
            CarbonImmutable::instance(Subscription::query()->findOrFail($fixture['sub'])->current_period_end)->equalTo($fixture['period_end']),
            'The renewal advanced the period in the attempt it should have given up.',
        );
    }

    #[Test]
    public function a_lapse_that_credits_the_wallet_and_a_sibling_downgrading_onto_the_plan_it_restores_both_complete(): void
    {
        $this->assertALapseAndASiblingDowngradeBothComplete(walletExists: true);
    }

    #[Test]
    public function the_same_when_the_lapse_opens_the_customers_first_wallet(): void
    {
        // The wallet row does not exist yet: the lapse inserts it, and the
        // downgrade's own insert of it waits on that - the same cycle, met on
        // the wallets unique index rather than on the row.
        $this->assertALapseAndASiblingDowngradeBothComplete(walletExists: false);
    }

    private function assertALapseAndASiblingDowngradeBothComplete(bool $walletExists): void
    {
        /*
         * OA-1, round four's re-audit (40P01 4/4 in two real processes): the
         * renewal's lapse of subscription A's part-paid upgrade took the
         * invoice, A, the wallet (returning what the upgrade held), and then,
         * voiding it, the plan A goes back to (RestorePlanOnVoidedUpgrade ->
         * PlanCapacity::lock(small)). The same customer's sibling B,
         * downgrading onto small, took B, its orders and invoices, the small
         * plan (claiming the unit), and then the wallet (the downgrade's
         * credit). Wallet -> plans against plans -> wallet. ApplyPlanChange
         * now takes the wallet before the plan, the order WhatAnInvoiceStillHolds
         * declares (the wallet, then plans).
         *
         * Deterministic: the renewal is stopped right after it locks the
         * wallet; the downgrade is let run until it waits on a row lock; the
         * renewal is then released to void the upgrade and lock the plan.
         */
        $fixture = $this->upgradeLeftOpen();
        $customer = Customer::query()->findOrFail($fixture['customer']);

        // What the lapse returns: a part payment of the upgrade by card.
        app(SettleInvoice::class)->execute(
            Invoice::query()->findOrFail($fixture['x']),
            Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor(1_000, 'KWD'))->create(),
        );

        if ($walletExists) {
            app(WalletLedger::class)->walletFor($customer, 'KWD');
        }

        // The sibling: the same customer, a paid period on a bigger plan.
        $sibling = $this->paidSubscriptionOn($customer, $this->plan(Product::query()->findOrFail(Plan::query()->findOrFail($fixture['small'])->product_id), 'mid', ['vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 160], 30_000));

        DB::select('SELECT pg_advisory_lock(?)', [self::RENEWAL_BARRIER]);

        try {
            $renew = $this->start(
                ['renew', $fixture['sub'], $fixture['period_end']->addSecond()->toIso8601String()],
                ['RACER_PAUSE_AFTER' => '/from "wallets".*for update/i', 'RACER_PAUSE_LOCK' => (string) self::RENEWAL_BARRIER],
            );
            $this->waitUntilWaitingOnAdvisory(1);

            $change = $this->start(['change', $sibling, $fixture['small'], $fixture['small_price'], $fixture['user']]);
            $this->waitUntil(fn (): bool => ! $change->isRunning() || $this->waiting() >= 1, 'The downgrade neither finished nor waited.');

            DB::select('SELECT pg_advisory_unlock(?)', [self::RENEWAL_BARRIER]);
        } finally {
            DB::select('SELECT pg_advisory_unlock_all()');
        }

        $this->assertNoDeadlock([$this->verdict($renew), $this->verdict($change)]);

        // Both did what they were for: the upgrade lapsed and A is back on
        // small; B moved onto small.
        $this->assertSame(InvoiceStatus::Void, Invoice::query()->findOrFail($fixture['x'])->status);
        $this->assertSame($fixture['small'], Subscription::query()->findOrFail($fixture['sub'])->plan_id);
        $this->assertSame($fixture['small'], Subscription::query()->findOrFail($sibling)->plan_id);
    }

    /**
     * A subscription of the customer's, twenty days into a paid period on the
     * plan given; its id.
     *
     * @param  array{Plan, PlanPrice}  $plan
     */
    private function paidSubscriptionOn(Customer $customer, array $plan): string
    {
        [$onPlan, $price] = $plan;

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::now('UTC')->startOfSecond()->subDays(20))
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $onPlan->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => $price->recurring_amount_minor,
            ])
            ->refresh();

        $period = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => $price->recurring_amount_minor,
            'total_minor' => $price->recurring_amount_minor,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $period->getKey(),
            'kind' => InvoiceItemKind::Plan,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_amount_minor' => $price->recurring_amount_minor,
            'total_minor' => $price->recurring_amount_minor,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'subscription_id' => $subscription->getKey(),
        ]);
        app(SettleInvoice::class)->execute($period, Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor($price->recurring_amount_minor, 'KWD'))->create());

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'subscription_id' => $subscription->getKey(),
            'resources' => $onPlan->resources,
        ]);
        VirtualMachine::factory()
            ->onNode(ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->getKey()]), 900)
            ->forService($service)
            ->create(['vcpu' => $onPlan->resources['vcpu'], 'memory_mib' => $onPlan->resources['memory_mib'], 'disk_gib' => $onPlan->resources['disk_gib']]);

        return (string) $subscription->getKey();
    }

    private const int RENEWAL_BARRIER = 424301;

    private const int VOID_BARRIER = 424302;

    /**
     * @param  array{sub: string, x: string, customer: string, due: int, small: string, small_price: string, user: string, period_end: CarbonImmutable}  $fixture
     * @param  list<string>  $first
     * @return list<array{completed: bool, error: ?string, sqlstate: ?string}>
     */
    private function race(array $fixture, array $first): array
    {
        config(['database.connections.pgsql_race_hold' => config('database.connections.'.config('database.default'))]);
        $hold = DB::connection('pgsql_race_hold');
        $hold->beginTransaction();
        $hold->table('invoices')->where('id', $fixture['x'])->lockForUpdate()->first();
        $hold->table('subscriptions')->where('id', $fixture['sub'])->lockForUpdate()->first();

        try {
            $a = $this->start($first);
            $this->waitUntilWaiting(1);

            $b = $this->start(['change', $fixture['sub'], $fixture['small'], $fixture['small_price'], $fixture['user']]);
            $this->waitUntilWaiting(2);

            // The upgrade is paid - a capture, and the invoice settled - and
            // both rows are released at once.
            $hold->table('transactions')->insert([
                'id' => (string) Str::ulid(),
                'customer_id' => $fixture['customer'],
                'invoice_id' => $fixture['x'],
                'provider' => 'fake',
                'provider_reference' => 'fake_pi_raced_'.Str::lower(Str::random(8)),
                'kind' => 'charge',
                'status' => 'succeeded',
                'amount_minor' => $fixture['due'],
                'currency' => 'KWD',
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $hold->table('invoices')->where('id', $fixture['x'])->update([
                'status' => InvoiceStatus::Paid->value,
                'amount_paid_minor' => $fixture['due'],
                'paid_at' => now(),
            ]);
            $hold->commit();
        } finally {
            if ($hold->transactionLevel() > 0) {
                $hold->rollBack();
            }

            DB::purge('pgsql_race_hold');
        }

        return [$this->verdict($a), $this->verdict($b)];
    }

    /**
     * @param  list<array{completed: bool, error: ?string, sqlstate: ?string}>  $outcomes
     */
    private function assertNoDeadlock(array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            $this->assertNotSame('40P01', $outcome['sqlstate'], 'A renewal-side action and a downgrade deadlocked: '.json_encode($outcomes));
        }

        foreach ($outcomes as $outcome) {
            $this->assertTrue($outcome['completed'], 'A racer did not complete: '.json_encode($outcomes));
        }
    }

    /**
     * A subscription twenty days into a paid small-plan period, moved to
     * large, the upgrade's invoice open.
     *
     * With $withoutTheUpgrade, the subscription as it stood before the change
     * (and the large plan and price to make it with).
     *
     * @return array{sub: string, x: string, customer: string, due: int, small: string, small_price: string, user: string, period_end: CarbonImmutable, large?: Plan, large_price?: PlanPrice}
     */
    private function upgradeLeftOpen(bool $withoutTheUpgrade = false): array
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        [$small, $smallPrice] = $this->plan($product, 'small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        [$large, $largePrice] = $this->plan($product, 'large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::now('UTC')->startOfSecond()->subDays(20))
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $small->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => 9_000,
            ])
            ->refresh();

        $period = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 9_000,
            'total_minor' => 9_000,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $period->getKey(),
            'kind' => InvoiceItemKind::Plan,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_amount_minor' => 9_000,
            'total_minor' => 9_000,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'subscription_id' => $subscription->getKey(),
        ]);
        app(SettleInvoice::class)->execute($period, Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor(9_000, 'KWD'))->create());

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'subscription_id' => $subscription->getKey(),
            'resources' => $small->resources,
        ]);
        VirtualMachine::factory()
            ->onNode(ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->getKey()]), 900)
            ->forService($service)
            ->create(['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160]);

        if ($withoutTheUpgrade) {
            return [
                'sub' => (string) $subscription->getKey(),
                'x' => '',
                'customer' => (string) $customer->getKey(),
                'due' => 0,
                'small' => (string) $small->getKey(),
                'small_price' => (string) $smallPrice->getKey(),
                'user' => (string) $user->getKey(),
                'period_end' => CarbonImmutable::instance($subscription->fresh()->current_period_end),
                'large' => $large,
                'large_price' => $largePrice,
            ];
        }

        app(ApplyPlanChange::class)->execute($subscription->fresh(), $large, $largePrice, 'raced-up', $user);

        /** @var Invoice $upgrade */
        $upgrade = Invoice::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('status', InvoiceStatus::Open->value)
            ->sole();

        return [
            'sub' => (string) $subscription->getKey(),
            'x' => (string) $upgrade->getKey(),
            'customer' => (string) $customer->getKey(),
            'due' => $upgrade->amountDue()->minorUnits(),
            'small' => (string) $small->getKey(),
            'small_price' => (string) $smallPrice->getKey(),
            'user' => (string) $user->getKey(),
            'period_end' => CarbonImmutable::instance($subscription->fresh()->current_period_end),
        ];
    }

    /**
     * @param  array<string, int>  $resources
     * @return array{Plan, PlanPrice}
     */
    private function plan(Product $product, string $slug, array $resources, int $minor): array
    {
        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => $slug,
            'resources' => $resources,
            'is_active' => true,
            'is_public' => true,
            'stock_limit' => null,
        ]);

        $price = PlanPrice::factory()->create([
            'plan_id' => $plan->getKey(),
            'currency' => 'KWD',
            'billing_period' => BillingPeriod::Monthly,
            'recurring_amount_minor' => $minor,
            'setup_amount_minor' => 0,
            'is_active' => true,
        ]);

        return [$plan, $price];
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function start(array $arguments, array $environment = []): Process
    {
        $process = new Process(
            ['php', __DIR__.'/subscription_lock_racer.php', ...$arguments],
            base_path(),
            ['APP_ENV' => 'testing', ...$environment],
            null,
            60.0,
        );

        $process->start();

        return $process;
    }

    private function waiting(): int
    {
        return (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND wait_event IN ('transactionid', 'tuple')");
    }

    private function waitUntilWaitingOnAdvisory(int $count): void
    {
        $this->waitUntil(
            static fn (): bool => (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND wait_event = 'advisory'") >= $count,
            'The racers never reached their barriers, so nothing was raced.',
        );
    }

    private function waitUntil(callable $condition, string $failure): void
    {
        $deadline = microtime(true) + 45.0;

        while (! $condition()) {
            if (microtime(true) > $deadline) {
                $this->fail($failure);
            }

            usleep(20_000);
        }
    }

    /** Until this many other backends of this database are waiting on a row lock. */
    private function waitUntilWaiting(int $count): void
    {
        $deadline = microtime(true) + 45.0;

        while ((int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND wait_event IN ('transactionid', 'tuple')") < $count) {
            if (microtime(true) > $deadline) {
                $this->fail('The racers never reached their row locks, so nothing was raced.');
            }

            usleep(20_000);
        }
    }

    /**
     * @return array{completed: bool, error: ?string, sqlstate: ?string}
     */
    private function verdict(Process $process): array
    {
        $process->wait();
        $line = trim($process->getOutput());
        $this->assertNotSame('', $line, 'A racer produced no verdict: '.$process->getErrorOutput());

        /** @var array{completed: bool, error: ?string, sqlstate: ?string} $decoded */
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
