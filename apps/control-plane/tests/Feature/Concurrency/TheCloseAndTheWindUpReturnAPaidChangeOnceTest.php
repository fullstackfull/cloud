<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Queries\WhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceItemKind;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Billing\Infrastructure\Models\InvoiceItem;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Catalog\Infrastructure\Models\Product;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\PlanChange;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * The close of a paid change's job and the wind-up of its subscription, in
 * two processes at once, return its payment once (R10-M).
 *
 * Both ask ReturnAnUpgradeTheEndPrevented for the same paid proration
 * invoice: the close inside its own transaction, holding the job; the
 * wind-up holding the subscription and then the paid upgrades it locks. The
 * figure each returns is read under the invoice's lock
 * (ReturnWhatAnInvoiceStillHolds), so the second sees the first's credit.
 *
 * Deterministic: a third connection holds the customer's wallet, so the close
 * stops after it has locked the invoice and read what it holds, and before it
 * posts the credit; the wind-up is started then, and waits for the invoice.
 * The wallet is let go, and between them 27.000 goes back, not 54.000 - and
 * not nothing.
 */
final class TheCloseAndTheWindUpReturnAPaidChangeOnceTest extends TestCase
{
    use LeavesNothingCommitted;
    use RefreshDatabase;

    private const int PAID = 27_000;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $this->emptyEveryTable();

        parent::tearDown();
    }

    #[Test]
    public function a_close_and_a_wind_up_at_once_return_a_paid_change_once(): void
    {
        $product = Product::factory()->create(['kind' => 'vps']);
        $small = Plan::factory()->create(['product_id' => $product->getKey(), 'slug' => 'small', 'resources' => ['vcpu' => 2, 'memory_mib' => 4096, 'disk_gib' => 40]]);
        $large = Plan::factory()->create(['product_id' => $product->getKey(), 'slug' => 'large', 'resources' => ['vcpu' => 8, 'memory_mib' => 16384, 'disk_gib' => 40]]);

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
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
            ]);

        // The service ended and the wind-up has not run yet.
        $service = Service::factory()->create([
            'customer_id' => $customer->getKey(),
            'kind' => 'vps',
            'subscription_id' => $subscription->getKey(),
            'status' => ServiceStatus::Terminated,
        ]);

        $invoice = Invoice::factory()->paid()->create([
            'customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'currency' => 'KWD',
            'subtotal_minor' => self::PAID,
            'total_minor' => self::PAID,
            'amount_paid_minor' => self::PAID,
        ]);
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->getKey(),
            'kind' => InvoiceItemKind::Proration,
            'description' => 'Upgrade',
            'quantity' => 1,
            'unit_amount_minor' => self::PAID,
            'total_minor' => self::PAID,
            'subscription_id' => $subscription->getKey(),
        ]);
        Transaction::factory()->create(['customer_id' => $customer->getKey(), 'invoice_id' => $invoice->getKey(), 'amount_minor' => self::PAID, 'currency' => 'KWD']);
        $this->assertSame(self::PAID, WhatAnInvoiceStillHolds::minor($invoice));

        $change = PlanChange::query()->create([
            'subscription_id' => $subscription->getKey(),
            'from_plan_id' => $small->getKey(),
            'to_plan_id' => $large->getKey(),
            'currency' => 'KWD',
            'units' => 1,
            'credit_minor' => 0,
            'charge_minor' => self::PAID,
            'wallet_credit_minor' => 0,
            'from_recurring_amount_minor' => 9_000,
            'proration_invoice_id' => $invoice->getKey(),
            'resources' => $large->resources,
            'changed_at' => now()->subDay(),
            'delivered_at' => now()->subDay(),
        ]);

        $job = ProvisioningJob::factory()->kind(ProvisioningJobKind::Resize)->create([
            'status' => ProvisioningJobStatus::NeedsReview,
            'service_id' => $service->getKey(),
            'customer_id' => $customer->getKey(),
            'idempotency_key' => sprintf('plan-change:%s:%s:invoice:%s', $subscription->getKey(), $large->getKey(), $invoice->getKey()),
        ]);

        config(['database.connections.pgsql_wallet_hold' => config('database.connections.'.config('database.default'))]);
        $hold = DB::connection('pgsql_wallet_hold');
        $hold->beginTransaction();
        $hold->table('wallets')->where('id', $wallet->getKey())->lockForUpdate()->first();

        try {
            $close = $this->start(['close', (string) $job->getKey(), (string) $service->getKey()]);
            $this->waitUntil(fn (): bool => $this->waiting() >= 1, 'The close never reached the wallet.');

            $windUp = $this->start(['wind-up', (string) $job->getKey(), (string) $service->getKey()]);

            // The wind-up waits for the invoice the close holds, or - with
            // nothing serialising them - reaches the wallet too.
            $this->waitUntil(fn (): bool => $this->waiting() >= 2 || ! $windUp->isRunning(), 'The wind-up neither waited nor finished.');
        } finally {
            $hold->rollBack();
            DB::purge('pgsql_wallet_hold');
        }

        $close->wait();
        $windUp->wait();

        $this->assertStringContainsString('"completed":true', $close->getOutput(), 'The close did not complete: '.$close->getOutput().$close->getErrorOutput());
        $this->assertStringContainsString('"completed":true', $windUp->getOutput(), 'The wind-up did not complete: '.$windUp->getOutput().$windUp->getErrorOutput());
        $this->assertSame(ProvisioningJobStatus::Cancelled, $job->refresh()->status);
        $this->assertTrue($subscription->refresh()->status->isTerminal(), 'The wind-up did not end the subscription.');

        $returned = (int) DB::table('wallet_transactions')
            ->where('invoice_id', $invoice->getKey())
            ->where('kind', WalletTransactionKind::Topup->value)
            ->sum('amount_minor');

        $this->assertSame(self::PAID, $returned, sprintf('%d returned against an invoice that took %d.', $returned, self::PAID));
        $this->assertSame(self::PAID, $ledger->balance($wallet->refresh())->minorUnits());
        $this->assertNotNull($change->refresh()->returned_at);
        $this->assertSame(1, DB::table('notifications')->where('customer_id', $customer->getKey())->where('type', 'billing.plan_change_returned_at_the_end')->count());
    }

    /**
     * @param  list<string>  $arguments
     */
    private function start(array $arguments): Process
    {
        $process = new Process(['php', __DIR__.'/plan_change_return_racer.php', ...$arguments], base_path(), ['APP_ENV' => 'testing'], null, 60.0);
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
