<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Money returned to a customer must show up on the document it came off.
 *
 * IssueRefund sent the money and announced RefundIssued; nothing listened, and
 * RecordInvoiceRefund — written, documented and tested — had no caller. A fully
 * refunded invoice therefore still read as paid in full with nothing refunded,
 * on the platform's books and on the customer's copy alike, and SettleInvoice
 * would have refused the re-payment that a refunded invoice is supposed to
 * accept.
 */
final class ARefundReachesTheInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private IssueRefund $issue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->issue = new IssueRefund(new PaymentProviderRegistry($this->app), app(WalletLedger::class));
    }

    /**
     * A paid invoice and the capture that paid it.
     *
     * @return array{0: Invoice, 1: Transaction}
     */
    private function paidInvoice(int $minor = 9_000): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'currency' => 'KWD',
            'status' => InvoiceStatus::Paid,
            'subtotal_minor' => $minor,
            'total_minor' => $minor,
            'amount_paid_minor' => $minor,
            'amount_refunded_minor' => 0,
        ]);

        $transaction = Transaction::factory()
            ->amount(Money::ofMinor($minor, 'KWD'))
            ->create([
                'customer_id' => $customer->id,
                'invoice_id' => $invoice->id,
            ]);

        return [$invoice, $transaction];
    }

    #[Test]
    public function a_partial_refund_is_recorded_on_the_invoice(): void
    {
        [$invoice, $transaction] = $this->paidInvoice(9_000);

        $this->issue->execute($transaction, Money::ofMinor(3_000, 'KWD'), 'requested_by_customer');

        $invoice->refresh();

        // Recorded, not deducted: the invoice still says what was billed.
        $this->assertSame(9_000, $invoice->total_minor);
        $this->assertSame(3_000, $invoice->amount_refunded_minor);
    }

    #[Test]
    public function a_full_refund_leaves_the_invoice_saying_so(): void
    {
        [$invoice, $transaction] = $this->paidInvoice(9_000);

        $this->issue->execute($transaction, Money::ofMinor(9_000, 'KWD'), 'duplicate_charge');

        $invoice->refresh();

        $this->assertSame(9_000, $invoice->amount_refunded_minor);
        // What the customer now owes again: the whole thing. An invoice that
        // was refunded but still reads as settled is one nobody will collect.
        $this->assertSame(9_000, $invoice->amount_due_minor);
    }

    #[Test]
    public function two_refunds_are_both_recorded(): void
    {
        [$invoice, $transaction] = $this->paidInvoice(9_000);

        $this->issue->execute($transaction, Money::ofMinor(2_000, 'KWD'), 'requested_by_customer');
        $this->issue->execute($transaction->refresh(), Money::ofMinor(1_500, 'KWD'), 'requested_by_customer');

        $this->assertSame(3_500, $invoice->refresh()->amount_refunded_minor);
    }

    #[Test]
    public function a_refund_of_a_payment_with_no_invoice_records_nothing_and_does_not_fail(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

        $transaction = Transaction::factory()
            ->amount(Money::ofMinor(5_000, 'KWD'))
            ->create(['customer_id' => $customer->id, 'invoice_id' => null]);

        $refund = $this->issue->execute($transaction, Money::ofMinor(5_000, 'KWD'), 'over_collection');

        // There is no document to record it on; the transaction and the refund
        // row carry the history.
        $this->assertSame(5_000, $refund->amount_minor);
    }
}
