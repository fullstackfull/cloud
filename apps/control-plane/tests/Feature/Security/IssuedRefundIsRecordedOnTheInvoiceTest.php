<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: a refund issued through IssueRefund must reach its invoice.
 *
 * IssueRefund writes the refund row with `invoice_id` already set (it defaults
 * to the capture's invoice). RecordInvoiceRefund used exactly that column as
 * its "have I already recorded this?" marker: attach() returned false when the
 * refund was already attached, and a false there made the whole call a no-op.
 *
 * So the first — and only — booking of a genuine refund was mistaken for a
 * redelivery. amount_refunded_minor stayed at zero, amount_due (a generated
 * column over total - paid + refunded) kept saying the invoice was settled, and
 * the money that went back to the customer was invisible to the books.
 *
 * The marker is now `recorded_on_invoice_at`, which only the booking writes.
 * The existing suite missed this because its refund rows are hand-built
 * detached (see InvoiceVoidAndRefundTest::refundRow), which is the one shape
 * IssueRefund never produces.
 */
final class IssuedRefundIsRecordedOnTheInvoiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_invoice_learns_about_a_refund_that_was_actually_paid_out(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()
            ->open()
            ->totalling(Money::ofMinor(30000, 'KWD'))
            ->create(['customer_id' => $customer->id]);

        $capture = $this->capture($invoice, Money::ofMinor(30000, 'KWD'));

        $paid = app(SettleInvoice::class)->execute($invoice, $capture)->invoice;
        $this->assertSame(InvoiceStatus::Paid, $paid->status);
        $this->assertSame(0, $paid->amount_due_minor);

        // Real money goes back through the provider adapter.
        $refund = app(IssueRefund::class)->execute(
            $capture->refresh(),
            Money::ofMinor(10000, 'KWD'),
            'requested_by_customer',
        );

        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        // IssueRefund attached it to the invoice when it created the row.
        $this->assertSame($paid->id, $refund->invoice_id);

        // Now book it against the document, exactly as a RefundIssued listener
        // would have to.
        $recorded = app(RecordInvoiceRefund::class)->execute($paid, $refund->amount(), $refund);

        $this->assertSame(
            10000,
            $recorded->fresh()->amount_refunded_minor,
            'The invoice recorded no refund even though 10.000 KWD was paid back.',
        );
        $this->assertSame(
            10000,
            $recorded->fresh()->amount_due_minor,
            'The invoice still reads as fully settled after a partial refund.',
        );
        $this->assertNotNull($refund->fresh()->recorded_on_invoice_at);

        // Booking the same refund again is still a no-op: the marker is set.
        $again = app(RecordInvoiceRefund::class)->execute($recorded, $refund->amount(), $refund->fresh());

        $this->assertSame(10000, $again->fresh()->amount_refunded_minor);
        $this->assertSame(InvoiceStatus::Paid, $again->fresh()->status);
    }

    private function capture(Invoice $invoice, Money $amount): Transaction
    {
        /** @var Transaction $transaction */
        $transaction = Transaction::query()->create([
            'customer_id' => $invoice->customer_id,
            'provider' => 'fake',
            'kind' => TransactionKind::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'provider_reference' => 'pi_'.Str::random(16),
            'processed_at' => now(),
        ]);

        // Resolve the registry once so the fake adapter is memoised the same
        // way the application resolves it.
        app(PaymentProviderRegistry::class)->get('fake');

        return $transaction;
    }
}
