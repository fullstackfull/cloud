<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two paths that lock the same two rows must lock them in the same order.
 *
 * A deadlock does not need a bug in either path. It needs one path to take the
 * payment row and then the invoice, and another to take the invoice and then
 * the payment, and enough traffic for the two to meet — at which point
 * PostgreSQL kills one of them, and what it kills is a customer's settlement or
 * an operator's refund. The convention is written down in SettleInvoice ("the
 * payment-side row first, then the invoice") and repeated in
 * RecordInvoiceRefund. This is what checks that the code still obeys it.
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
