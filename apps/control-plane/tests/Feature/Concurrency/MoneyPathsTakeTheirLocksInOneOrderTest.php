<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Events\InvoicePaid;
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
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\ApplyPlanChange;
use Lynomia\Modules\Subscriptions\Application\Actions\RenewSubscription;
use Lynomia\Modules\Subscriptions\Application\Actions\WithdrawAnUnpaidPlanChange;
use Lynomia\Modules\Subscriptions\Application\Listeners\ResizeOnPlanChangeSettlement;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Application\Actions\PayInvoiceFromWallet;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Exceptions\IdempotencyKeyConflictException;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two paths that lock the same two rows must lock them in the same order.
 *
 * A deadlock does not need a bug in either path. It needs one path to take the
 * payment row and then the invoice, and another to take the invoice and then
 * the payment, and enough traffic for the two to meet — at which point
 * PostgreSQL kills one of them, and what it kills is a customer's settlement or
 * an operator's refund. The convention is written down once, in
 * WhatAnInvoiceStillHolds (the payment-side row, then the invoice, then the
 * subscription, then the wallet, then plans), and SettleInvoice, IssueRefund,
 * RecordInvoiceRefund and PayInvoiceFromWallet each point to it. This is what
 * checks that the code still obeys it; ARefundAndASettlementDoNotDeadlockTest
 * races the pair that did not (N-2) in two real processes.
 *
 * Checked from the statements the actions actually issue, in the order they
 * issue them, rather than from the order the calls appear in the source: the
 * refund lock is taken inside a private method defined below the invoice lock
 * and executed before it, so reading the file top to bottom gives the wrong
 * answer. A deadlock is a property of execution order, so execution order is
 * what is measured.
 *
 * A single-threaded test cannot make a real deadlock happen — one side would
 * have to be suspended mid-transaction while the other advances, and a
 * suspended side holds its locks — so this asserts the property that prevents
 * one instead of trying to observe the failure.
 */
final class MoneyPathsTakeTheirLocksInOneOrderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The tables named by `select ... for update` statements, in the order the
     * statements ran.
     *
     * @return list<string>
     */
    private function lockOrderOf(callable $work): array
    {
        /** @var list<string> $locked */
        $locked = [];

        DB::listen(static function ($query) use (&$locked): void {
            if (! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            if (preg_match('/from\s+"([a-z_]+)"/i', $query->sql, $matches) === 1) {
                $locked[] = $matches[1];
            }
        });

        $work();

        return $locked;
    }

    /**
     * @return array{0: Invoice, 1: Transaction}
     */
    private function paidInvoice(int $minor = 9_000): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => $minor,
            'total_minor' => $minor,
            'amount_paid_minor' => 0,
            'amount_refunded_minor' => 0,
        ]);

        $transaction = Transaction::factory()
            ->amount(Money::ofMinor($minor, 'KWD'))
            ->create(['customer_id' => $customer->id, 'invoice_id' => $invoice->id]);

        return [$invoice, $transaction];
    }

    #[Test]
    public function settling_an_invoice_locks_the_payment_before_the_invoice(): void
    {
        [$invoice, $transaction] = $this->paidInvoice();

        $order = $this->lockOrderOf(function () use ($invoice, $transaction): void {
            app(SettleInvoice::class)->execute($invoice, $transaction);
        });

        $this->assertContains('transactions', $order);
        $this->assertContains('invoices', $order);
        $this->assertLessThan(
            array_search('invoices', $order, true),
            array_search('transactions', $order, true),
            'SettleInvoice must take the payment-side row before the invoice.',
        );
    }

    #[Test]
    public function issuing_a_refund_locks_the_capture_before_the_invoice(): void
    {
        [$invoice, $transaction] = $this->paidInvoice();
        app(SettleInvoice::class)->execute($invoice, $transaction);

        $order = $this->lockOrderOf(function () use ($transaction): void {
            app(IssueRefund::class)->execute($transaction->refresh(), Money::ofMinor(1_000, 'KWD'), 'part refund');
        });

        $this->assertContains('transactions', $order);
        $this->assertContains('invoices', $order);
        $this->assertLessThan(
            array_search('invoices', $order, true),
            array_search('transactions', $order, true),
            'IssueRefund must take the capture before the invoice, the order SettleInvoice takes them in (N-2).',
        );
    }

    #[Test]
    public function a_repeated_wallet_payment_locks_no_charge_after_its_invoice(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 9_000,
            'total_minor' => 9_000,
            'amount_paid_minor' => 0,
            'amount_refunded_minor' => 0,
        ]);

        $ledger = app(WalletLedger::class);
        $ledger->credit($ledger->walletFor($customer, 'KWD'), Money::ofMinor(9_000, 'KWD'), WalletTransactionKind::Topup, 'credit');

        $first = app(PayInvoiceFromWallet::class)->execute($customer, $invoice, 'pay-once');
        $this->assertSame(InvoiceStatus::Paid, $first->invoice->status);

        // The charge the first request wrote now exists and can be refunded;
        // a replay that locked it after the invoice would invert the order.
        $order = $this->lockOrderOf(function () use ($customer, $invoice): void {
            $replay = app(PayInvoiceFromWallet::class)->execute($customer, $invoice, 'pay-once');
            $this->assertTrue($replay->movedNothing());
            $this->assertSame(InvoiceStatus::Paid, $replay->invoice->status);
        });

        $this->assertSame(['invoices'], array_values(array_unique($order)), 'A replay locked more than its invoice: '.implode(', ', $order));

        // R4: the same key on a different invoice is not a replay of it. It
        // is refused, rather than answered "nothing moved" for an invoice
        // that is still owed.
        /** @var Invoice $other */
        $other = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 1_000,
            'total_minor' => 1_000,
            'amount_paid_minor' => 0,
            'amount_refunded_minor' => 0,
        ]);

        try {
            app(PayInvoiceFromWallet::class)->execute($customer, $other, 'pay-once');
            $this->fail('A key that paid one invoice answered for another.');
        } catch (IdempotencyKeyConflictException $e) {
            $this->assertSame('wallet.idempotency_key_conflict', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }

        $this->assertSame(InvoiceStatus::Open, $other->refresh()->status);
        $this->assertSame(0, $other->amount_paid_minor);
    }

    #[Test]
    public function a_downgrade_that_credits_the_wallet_locks_the_wallet_before_the_plan(): void
    {
        /*
         * OA-1, round four's re-audit: ApplyPlanChange took the plan it moves
         * onto (claiming the unit) and only then the wallet (the credit),
         * while a renewal lapsing a sibling's upgrade took the wallet and then
         * the plan the void restores. The declared order is the wallet, then
         * plans.
         */
        [$customer, $user, $subscription, $small, $smallPrice] = $this->aPaidSubscription(onSmall: false);

        $order = $this->firstLocksOf(function () use ($subscription, $small, $smallPrice, $user): void {
            app(ApplyPlanChange::class)->execute($subscription, $small, $smallPrice, 'lock-order-down', $user);
        });

        $ledger = app(WalletLedger::class);
        $this->assertSame($small->getKey(), $subscription->fresh()?->plan_id);
        $this->assertGreaterThan(0, $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits(), 'The downgrade credited nothing, so nothing was measured.');
        $this->assertTakenInOrder($order, ['subscriptions', 'wallets', 'plans'], 'ApplyPlanChange (a downgrade)');
    }

    #[Test]
    public function a_renewal_lapsing_an_upgrade_locks_the_wallet_before_the_plan_it_restores(): void
    {
        [$customer, $user, $subscription, $small, , $large, $largePrice] = $this->aPaidSubscription(onSmall: true);

        app(ApplyPlanChange::class)->execute($subscription, $large, $largePrice, 'lock-order-up', $user);

        /** @var Invoice $upgrade */
        $upgrade = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();

        // Part paid, so the lapse has something to return to the wallet.
        app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor(1_000, 'KWD'))->create());

        $periodEnd = CarbonImmutable::instance(Subscription::query()->findOrFail($subscription->getKey())->current_period_end);

        $order = $this->firstLocksOf(function () use ($subscription, $periodEnd): void {
            app(RenewSubscription::class)->execute(Subscription::query()->findOrFail($subscription->getKey()), $periodEnd->addSecond());
        });

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status, 'Nothing lapsed, so nothing was measured.');
        $this->assertSame($small->getKey(), $subscription->fresh()?->plan_id);
        $this->assertTakenInOrder($order, ['subscriptions', 'wallets', 'plans'], 'RenewSubscription (a lapse)');
    }

    #[Test]
    public function a_customer_withdrawing_an_unpaid_change_takes_the_lapses_order(): void
    {
        /*
         * WithdrawAnUnpaidPlanChange is the lapse, asked for by the customer:
         * the invoice while it is open, then the subscription, the wallet
         * (returning the part paid) and the plan the void restores.
         */
        [$customer, $user, $subscription, $small, , $large, $largePrice] = $this->aPaidSubscription(onSmall: true);

        app(ApplyPlanChange::class)->execute($subscription, $large, $largePrice, 'lock-order-withdraw', $user);

        /** @var Invoice $upgrade */
        $upgrade = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();
        app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor(1_000, 'KWD'))->create());

        $order = $this->firstLocksOf(function () use ($upgrade, $user): void {
            app(WithdrawAnUnpaidPlanChange::class)->execute($upgrade->fresh() ?? $upgrade, $user);
        });

        $this->assertSame(InvoiceStatus::Void, $upgrade->fresh()?->status, 'Nothing was withdrawn, so nothing was measured.');
        $this->assertSame($small->getKey(), $subscription->fresh()?->plan_id);
        $this->assertTakenInOrder($order, ['invoices', 'subscriptions', 'wallets', 'plans'], 'WithdrawAnUnpaidPlanChange');
    }

    #[Test]
    public function a_settlement_returning_a_change_no_longer_deliverable_locks_the_invoice_and_wallet_before_the_plan(): void
    {
        /*
         * The settlement holds the subscription, returns what the paid
         * invoice holds (the invoice, then the wallet) and puts the
         * subscription back on the plan it left (the plan, last): the order
         * WhatAnInvoiceStillHolds declares for ResizeOnPlanChangeSettlement.
         */
        [$customer, $user, $subscription, $small, , $large, $largePrice] = $this->aPaidSubscription(onSmall: true);

        app(ApplyPlanChange::class)->execute($subscription, $large, $largePrice, 'lock-order-return', $user);

        /** @var Invoice $upgrade */
        $upgrade = Invoice::query()->where('subscription_id', $subscription->getKey())->where('status', InvoiceStatus::Open->value)->sole();

        // Captured with the settlement held back, and then the machine goes:
        // the change can no longer be delivered when the settlement is heard.
        Event::fakeFor(fn () => app(SettleInvoice::class)->execute($upgrade, Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor($upgrade->total_minor, 'KWD'))->create()), [InvoicePaid::class]);
        VirtualMachine::query()->delete();

        $event = new InvoicePaid((string) $upgrade->getKey(), (string) $customer->getKey(), null, (string) $subscription->getKey(), CarbonImmutable::now());

        $order = $this->firstLocksOf(function () use ($event): void {
            app(ResizeOnPlanChangeSettlement::class)->handle($event);
        });

        $this->assertSame($small->getKey(), $subscription->fresh()?->plan_id, 'Nothing was returned, so nothing was measured.');
        $this->assertTakenInOrder($order, ['subscriptions', 'invoices', 'wallets', 'plans'], 'ResizeOnPlanChangeSettlement (a change no longer deliverable)');
    }

    /**
     * The tables of the rows `select ... for update` statements locked, each
     * row counted at its first lock only: locking a row the transaction
     * already holds (the ledger re-locks the wallet a downgrade took early;
     * VoidInvoice re-locks the invoice the return locked) waits for nobody,
     * so it cannot close a cycle.
     *
     * @return list<string>
     */
    private function firstLocksOf(callable $work): array
    {
        /** @var list<string> $locked */
        $locked = [];
        /** @var array<string, true> $seen */
        $seen = [];

        DB::listen(static function ($query) use (&$locked, &$seen): void {
            if (! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            if (preg_match('/from\s+"([a-z_]+)"/i', $query->sql, $matches) !== 1) {
                return;
            }

            $row = $matches[1].':'.json_encode($query->bindings);

            if (! isset($seen[$row])) {
                $seen[$row] = true;
                $locked[] = $matches[1];
            }
        });

        $work();

        return $locked;
    }

    /**
     * Every table named is locked, and no row of one is first locked after a
     * row of a table later in the declared order was.
     *
     * @param  list<string>  $order
     * @param  list<string>  $expected
     */
    private function assertTakenInOrder(array $order, array $expected, string $path): void
    {
        $relevant = array_values(array_filter($order, static fn (string $table): bool => in_array($table, $expected, true)));

        foreach ($expected as $table) {
            $this->assertContains($table, $relevant, $path.' took no lock on '.$table.': '.implode(', ', $order));
        }

        $rank = array_flip($expected);
        $highest = -1;

        foreach ($relevant as $table) {
            if ($rank[$table] < $highest) {
                $this->fail(sprintf(
                    '%s locked %s after %s, against the declared order (%s): %s',
                    $path,
                    $table,
                    $expected[$highest],
                    implode(' -> ', $expected),
                    implode(', ', $order),
                ));
            }

            $highest = max($highest, $rank[$table]);
        }
    }

    /**
     * A customer's subscription on the large plan (or, with $onSmall, the
     * small one), twenty days into a period it paid for, with its machine;
     * and the product's two plans.
     *
     * @return array{0: Customer, 1: User, 2: Subscription, 3: Plan, 4: PlanPrice, 5: Plan, 6: PlanPrice}
     */
    private function aPaidSubscription(bool $onSmall): array
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        [$small, $smallPrice] = $this->planOf($product, 'small', ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160], 9_000);
        [$large, $largePrice] = $this->planOf($product, 'large', ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 160], 90_000);
        [$on, $onPrice] = $onSmall ? [$small, $smallPrice] : [$large, $largePrice];
        $recurring = $onPrice->recurring_amount_minor;

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::now('UTC')->startOfSecond()->subDays(20))
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $on->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => $recurring,
            ])
            ->refresh();

        $period = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => $recurring,
            'total_minor' => $recurring,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $period->getKey(),
            'kind' => InvoiceItemKind::Plan,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_amount_minor' => $recurring,
            'total_minor' => $recurring,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'subscription_id' => $subscription->getKey(),
        ]);
        app(SettleInvoice::class)->execute($period, Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor($recurring, 'KWD'))->create());

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'subscription_id' => $subscription->getKey(),
            'resources' => $on->resources,
        ]);
        VirtualMachine::factory()
            ->onNode(ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->getKey()]), 900)
            ->forService($service)
            ->create(['vcpu' => $on->resources['vcpu'], 'memory_mib' => $on->resources['memory_mib'], 'disk_gib' => $on->resources['disk_gib']]);

        return [$customer, $user, $subscription->fresh(), $small, $smallPrice, $large, $largePrice];
    }

    /**
     * @param  array<string, int>  $resources
     * @return array{Plan, PlanPrice}
     */
    private function planOf(Product $product, string $slug, array $resources, int $minor): array
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

    #[Test]
    public function recording_a_refund_locks_the_refund_before_the_invoice(): void
    {
        [$invoice, $transaction] = $this->paidInvoice();

        $invoice->forceFill([
            'status' => InvoiceStatus::Paid,
            'amount_paid_minor' => 9_000,
        ])->save();

        /** @var Refund $refund */
        $refund = Refund::factory()->create([
            'transaction_id' => $transaction->id,
            'amount_minor' => 3_000,
            'currency' => 'KWD',
        ]);

        $order = $this->lockOrderOf(function () use ($invoice, $refund): void {
            app(RecordInvoiceRefund::class)->execute(
                $invoice,
                Money::ofMinor(3_000, 'KWD'),
                $refund,
            );
        });

        $this->assertContains('refunds', $order);
        $this->assertContains('invoices', $order);
        $this->assertLessThan(
            array_search('invoices', $order, true),
            array_search('refunds', $order, true),
            'RecordInvoiceRefund must take the payment-side row before the invoice, '
            .'the same way SettleInvoice does, or the two can deadlock against each other.',
        );
    }
}
