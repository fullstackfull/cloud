<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * A refund of a capture and that capture's settlement, in two processes, both
 * complete (N-2).
 *
 * RecordPaymentCapture writes a capture already carrying its invoice's id, and
 * settlement follows from a queue. An operator refunding that capture in the
 * meantime used to take the invoice and then the capture, while SettleInvoice
 * takes the capture and then the invoice: a real cycle, which PostgreSQL broke
 * by killing one side with 40P01 - after the refund had been made at the
 * provider. Both now follow the money-path lock order WhatAnInvoiceStillHolds
 * writes down: the capture, then the invoice.
 *
 * Made deterministic rather than left to timing. A third connection holds the
 * invoice row; the refund is started first and allowed to reach its first
 * wait, then the settlement. Under the old order the refund queues on the
 * invoice without the capture and the settlement takes the capture and queues
 * behind it; releasing the invoice hands it to the refund, which then wants
 * the capture - the cycle, every time. Under the one order the refund holds
 * the capture while it waits, the settlement waits behind it for the capture,
 * and releasing the invoice lets them finish one after the other.
 *
 * The fixtures are committed, because another process cannot see a row that
 * was not, and LeavesNothingCommitted empties every table after.
 */
final class ARefundAndASettlementDoNotDeadlockTest extends TestCase
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
    public function a_refund_and_the_settlement_of_the_same_attached_capture_both_complete(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => 1_500,
            'total_minor' => 1_500,
            'amount_paid_minor' => 0,
            'amount_refunded_minor' => 0,
        ]);

        // Attached before settlement, as RecordPaymentCapture writes it.
        $capture = Transaction::factory()
            ->amount(Money::ofMinor(1_500, 'KWD'))
            ->create(['customer_id' => $customer->getKey(), 'invoice_id' => $invoice->getKey()]);

        config(['database.connections.pgsql_invoice_hold' => config('database.connections.'.config('database.default'))]);
        $hold = DB::connection('pgsql_invoice_hold');
        $hold->beginTransaction();
        $hold->table('invoices')->where('id', $invoice->getKey())->lockForUpdate()->first();

        try {
            $refund = $this->start(['refund', (string) $capture->getKey(), (string) $invoice->getKey(), '500']);
            $this->waitUntilWaiting(1);

            $settle = $this->start(['settle', (string) $capture->getKey(), (string) $invoice->getKey()]);
            $this->waitUntilWaiting(2);
        } finally {
            $hold->rollBack();
            DB::purge('pgsql_invoice_hold');
        }

        $outcomes = [$this->verdict($refund), $this->verdict($settle)];

        foreach ($outcomes as $outcome) {
            $this->assertNotSame('40P01', $outcome['sqlstate'], 'The two money paths deadlocked: '.json_encode($outcomes));
        }

        foreach ($outcomes as $i => $outcome) {
            $this->assertTrue($outcome['completed'], ($i === 0 ? 'The refund' : 'The settlement').' did not complete: '.json_encode($outcome));
        }

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(1_500, $invoice->amount_paid_minor);
        $this->assertSame(500, (int) Refund::query()->where('transaction_id', $capture->getKey())->sum('amount_minor'));
    }

    /**
     * @param  list<string>  $arguments
     */
    private function start(array $arguments): Process
    {
        $process = new Process(
            ['php', __DIR__.'/money_lock_racer.php', ...$arguments],
            base_path(),
            ['APP_ENV' => 'testing'],
            null,
            60.0,
        );

        $process->start();

        return $process;
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
