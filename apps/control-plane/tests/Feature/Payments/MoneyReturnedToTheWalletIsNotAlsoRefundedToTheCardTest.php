<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Application\Actions\CreditWhatACancelledOrderPaid;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceRefundExceedsPaymentException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\RefundExceedsCaptureException;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Money that went back to the customer's wallet against an invoice is not
 * refundable to the card as well.
 *
 * A capture's own balance (captured − refunded) did not know about wallet
 * credits made against the invoice it paid: SettleInvoice's overpayment
 * surplus, or a cancelled order's credit (CreditWhatACancelledOrderPaid). An
 * operator could then refund the whole capture to the card and the customer
 * held the same money twice. IssueRefund, RecordInvoiceRefund and the
 * cancelled-order credit now read one figure, WhatAnInvoiceStillHolds.
 */
final class MoneyReturnedToTheWalletIsNotAlsoRefundedToTheCardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_overpayment_surplus_in_the_wallet_is_not_also_refunded_to_the_card(): void
    {
        [$customer, $invoice] = $this->invoiceFor(1_500);

        $capture = $this->capture($customer, $invoice, 2_000);
        app(SettleInvoice::class)->execute($invoice, $capture);
        $this->assertSame(500, $this->wallet($customer), 'Precondition: the surplus went to the wallet.');

        try {
            app(IssueRefund::class)->execute($capture->refresh(), Money::ofMinor(2_000, 'KWD'), 'customer asked');
            $this->fail('The surplus already in the wallet was refunded to the card as well.');
        } catch (RefundExceedsCaptureException $e) {
            $this->assertSame('payment.refund_exceeds_what_is_held', $e->errorCode());
            $this->assertSame(1_500, $e->context()['refundable_minor']);
        }

        // Up to what the invoice still holds is refunded as before.
        $refund = app(IssueRefund::class)->execute($capture->refresh(), Money::ofMinor(1_500, 'KWD'), 'customer asked');
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame(500, $this->wallet($customer));
    }

    #[Test]
    public function a_cancelled_orders_credit_is_not_also_refunded_to_the_card(): void
    {
        [$customer, $invoice] = $this->invoiceFor(1_500, withOrder: true);

        $capture = $this->capture($customer, $invoice, 1_500);
        app(SettleInvoice::class)->execute($invoice, $capture);

        $this->assertSame(1_500, app(CreditWhatACancelledOrderPaid::class)->execute((string) $invoice->order_id));

        try {
            app(IssueRefund::class)->execute($capture->refresh(), Money::ofMinor(1, 'KWD'), 'customer asked');
            $this->fail('A cancelled order\'s credit was refunded to the card as well.');
        } catch (RefundExceedsCaptureException $e) {
            $this->assertSame('payment.refund_exceeds_what_is_held', $e->errorCode());
        }

        // Booking a refund straight onto the document is refused the same way.
        try {
            app(RecordInvoiceRefund::class)->execute($invoice->refresh(), Money::ofMinor(1_500, 'KWD'));
            $this->fail('A refund was booked on an invoice whose money is already in the wallet.');
        } catch (InvoiceRefundExceedsPaymentException) {
            // Expected.
        }
    }

    #[Test]
    public function a_spent_wallet_credit_is_not_clawed_back_and_the_card_refund_is_still_refused(): void
    {
        [$customer, $invoice] = $this->invoiceFor(1_500, withOrder: true);

        $capture = $this->capture($customer, $invoice, 1_500);
        app(SettleInvoice::class)->execute($invoice, $capture);
        app(CreditWhatACancelledOrderPaid::class)->execute((string) $invoice->order_id);

        // The customer spends the credit.
        $ledger = app(WalletLedger::class);
        $ledger->debit(
            wallet: $ledger->walletFor($customer, 'KWD'),
            amount: Money::ofMinor(1_500, 'KWD'),
            kind: WalletTransactionKind::Payment,
            description: 'Spent on another invoice',
        );
        $this->assertSame(0, $this->wallet($customer));

        try {
            app(IssueRefund::class)->execute($capture->refresh(), Money::ofMinor(1_500, 'KWD'), 'customer asked');
            $this->fail('Spent wallet credit was paid out again to the card.');
        } catch (RefundExceedsCaptureException $e) {
            $this->assertSame('payment.refund_exceeds_what_is_held', $e->errorCode());
        }

        // Nothing was taken back from the wallet either.
        $this->assertSame(0, $this->wallet($customer));
    }

    #[Test]
    public function a_refund_then_the_credit_agree_with_the_credit_then_a_refund(): void
    {
        [$customer, $invoice] = $this->invoiceFor(1_500, withOrder: true);

        $capture = $this->capture($customer, $invoice, 1_500);
        app(SettleInvoice::class)->execute($invoice, $capture);

        app(IssueRefund::class)->execute($capture->refresh(), Money::ofMinor(600, 'KWD'), 'part refund');

        $this->assertSame(900, app(CreditWhatACancelledOrderPaid::class)->execute((string) $invoice->order_id));
        $this->assertSame(900, $this->wallet($customer));
    }

    #[Test]
    public function a_card_refund_still_pending_at_the_provider_is_not_credited_to_the_wallet_as_well(): void
    {
        /*
         * The verifier's R2 shape: a 500 card refund is in flight (its row is
         * pending; the invoice has not booked it yet) when a cancelled order's
         * credit runs. Only what is left after it goes to the wallet.
         */
        [$customer, $invoice] = $this->invoiceFor(1_500, withOrder: true);

        $capture = $this->capture($customer, $invoice, 1_500);
        app(SettleInvoice::class)->execute($invoice, $capture);

        Refund::query()->create([
            'transaction_id' => $capture->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => 500,
            'currency' => 'KWD',
            'status' => RefundStatus::Pending,
            'reason' => 'in flight at the provider',
        ]);

        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor, 'Precondition: the invoice has not booked the pending refund.');

        $this->assertSame(1_000, app(CreditWhatACancelledOrderPaid::class)->execute((string) $invoice->order_id));
        $this->assertSame(1_000, $this->wallet($customer));
    }

    #[Test]
    public function a_refund_locks_the_invoice_before_the_capture(): void
    {
        /*
         * PayInvoiceFromWallet locks the invoice and then the wallet charge.
         * A refund taking the same two rows the other way round could
         * deadlock with it, so the refund takes the invoice first. A single
         * process cannot interleave the two; this reads the order the refund
         * takes its locks in.
         */
        [$customer, $invoice] = $this->invoiceFor(1_500);
        $capture = $this->capture($customer, $invoice, 1_500);
        app(SettleInvoice::class)->execute($invoice, $capture);

        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        app(IssueRefund::class)->execute($capture->refresh(), Money::ofMinor(500, 'KWD'), 'part refund');

        $invoiceLock = null;
        $captureLock = null;

        foreach ($statements as $i => $sql) {
            if (! str_contains($sql, 'for update')) {
                continue;
            }

            if ($invoiceLock === null && str_contains($sql, 'from "invoices"')) {
                $invoiceLock = $i;
            }

            if ($captureLock === null && str_contains($sql, 'from "transactions"')) {
                $captureLock = $i;
            }
        }

        $this->assertNotNull($invoiceLock, 'The refund never locked the invoice the capture paid.');
        $this->assertNotNull($captureLock);
        $this->assertLessThan($captureLock, $invoiceLock, 'The refund locked the capture before the invoice: the opposite order to PayInvoiceFromWallet.');
    }

    /**
     * @return array{Customer, Invoice}
     */
    private function invoiceFor(int $totalMinor, bool $withOrder = false): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'order_id' => $withOrder ? Order::factory()->create(['customer_id' => $customer->getKey()])->getKey() : null,
            'currency' => 'KWD',
            'subtotal_minor' => $totalMinor,
            'total_minor' => $totalMinor,
        ]);

        return [$customer, $invoice];
    }

    private function capture(Customer $customer, Invoice $invoice, int $minor): Transaction
    {
        return Transaction::factory()->amount(Money::ofMinor($minor, 'KWD'))->create([
            'customer_id' => $customer->getKey(),
            'invoice_id' => $invoice->getKey(),
        ]);
    }

    private function wallet(Customer $customer): int
    {
        $ledger = app(WalletLedger::class);

        return $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();
    }
}
