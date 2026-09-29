<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * A downgrade credit and a card refund of the same period's invoice, in two
 * processes, never return more than the invoice took (O-2, the lock half).
 *
 * The credit is sized from what the invoice still holds and recorded against
 * it; a card refund is held to what it still holds. Each is only as good as
 * the lock it is read under: ApplyPlanChange takes the invoice's lock
 * (MoneyCollectedForThePeriod::lockTheInvoicesItDrawsOn()) before it sizes the
 * credit, and IssueRefund takes it before it reserves. Without the first, a
 * downgrade reads 90.000 held, a refund of 90.000 reads nothing credited, and
 * both go through: 117.000 back for 90.000 paid.
 *
 * Deterministic: a third connection holds the customer's wallet, so the
 * downgrade stops after sizing its credit and before posting it; the refund
 * is started then. With the lock, the refund waits for the downgrade's
 * invoice lock and then sees the credit; without it, the refund reads nothing
 * credited and completes while the credit is still waiting to be posted.
 */
final class ADowngradeCreditAndACardRefundDrawOnAnInvoiceOnceTest extends TestCase
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
    public function a_downgrade_credit_and_a_refund_of_the_whole_period_return_no_more_than_was_paid(): void
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        [$large] = $this->plan($product, 'large', 90_000);
        [$lean, $leanPrice] = $this->plan($product, 'lean', 9_000);

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $user = User::factory()->create();
        $ledger = app(WalletLedger::class);
        $wallet = $ledger->walletFor($customer, 'KWD');

        $subscription = Subscription::factory()
            ->startingOn(CarbonImmutable::now('UTC')->startOfSecond()->subDays(20))
            ->create([
                'customer_id' => $customer->getKey(),
                'plan_id' => $large->getKey(),
                'currency' => 'KWD',
                'billing_period' => BillingPeriod::Monthly,
                'recurring_amount_minor' => 90_000,
            ])
            ->refresh();

        $period = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 90_000,
            'total_minor' => 90_000,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $period->getKey(),
            'kind' => InvoiceItemKind::Plan,
            'description' => 'Renewal',
            'quantity' => 1,
            'unit_amount_minor' => 90_000,
            'total_minor' => 90_000,
            'period_start' => $subscription->current_period_start,
            'period_end' => $subscription->current_period_end,
            'subscription_id' => $subscription->getKey(),
        ]);
        $capture = Transaction::factory()->forCustomer($customer)->amount(Money::ofMinor(90_000, 'KWD'))->create();
        app(SettleInvoice::class)->execute($period, $capture);

        $service = Service::factory()->active()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'subscription_id' => $subscription->getKey(),
            'resources' => $large->resources,
        ]);
        VirtualMachine::factory()
            ->onNode(ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->getKey()]), 900)
            ->forService($service)
            ->create(['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160]);

        config(['database.connections.pgsql_wallet_hold' => config('database.connections.'.config('database.default'))]);
        $hold = DB::connection('pgsql_wallet_hold');
        $hold->beginTransaction();
        $hold->table('wallets')->where('id', $wallet->getKey())->lockForUpdate()->first();

        try {
            $change = $this->start('subscription_lock_racer.php', ['change', (string) $subscription->getKey(), (string) $lean->getKey(), (string) $leanPrice->getKey(), (string) $user->getKey()]);
            $this->waitUntil(fn (): bool => $this->waiting() >= 1, 'The downgrade never reached the wallet.');

            $refund = $this->start('money_lock_racer.php', ['refund', (string) $capture->getKey(), (string) $period->getKey(), '90000']);

            // Either the refund waits for the downgrade's invoice lock, or -
            // with no such lock - it goes straight through.
            $this->waitUntil(fn (): bool => $this->waiting() >= 2 || ! $refund->isRunning(), 'The refund neither waited nor finished.');
        } finally {
            $hold->rollBack();
            DB::purge('pgsql_wallet_hold');
        }

        $change->wait();
        $refund->wait();

        $credited = (int) WalletTransaction::query()
            ->where('invoice_id', $period->getKey())
            ->where('kind', WalletTransactionKind::Adjustment->value)
            ->sum('amount_minor');
        $refunded = (int) Refund::query()
            ->where('transaction_id', $capture->getKey())
            ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Succeeded->value])
            ->sum('amount_minor');

        $this->assertStringContainsString('"completed":true', $change->getOutput(), 'The downgrade did not complete: '.$change->getOutput().$change->getErrorOutput());
        $this->assertLessThanOrEqual(
            90_000,
            $credited + $refunded,
            sprintf('%d credited and %d refunded against an invoice that took 90.000.', $credited, $refunded),
        );
    }

    /**
     * @return array{Plan, PlanPrice}
     */
    private function plan(Product $product, string $slug, int $minor): array
    {
        $plan = Plan::factory()->create([
            'product_id' => $product->getKey(),
            'slug' => $slug,
            'resources' => ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 160],
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
     */
    private function start(string $racer, array $arguments): Process
    {
        $process = new Process(['php', __DIR__.'/'.$racer, ...$arguments], base_path(), ['APP_ENV' => 'testing'], null, 60.0);
        $process->start();

        return $process;
    }

    private function waiting(): int
    {
        return (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND wait_event IN ('transactionid', 'tuple')");
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
}
