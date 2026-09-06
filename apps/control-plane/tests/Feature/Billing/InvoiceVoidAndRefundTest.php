<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\Actions\TransitionInvoice;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceRefundExceedsPaymentException;
use Lynomia\Modules\Billing\Domain\Exceptions\PaidInvoiceCannotBeVoidedException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InvoiceVoidAndRefundTest extends TestCase
{
    use RefreshDatabase;

    private VoidInvoice $void;

    private RecordInvoiceRefund $refund;

    private SettleInvoice $settle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->void = app(VoidInvoice::class);
        $this->refund = app(RecordInvoiceRefund::class);
        $this->settle = app(SettleInvoice::class);
    }

    #[Test]
    public function an_unpaid_invoice_may_be_voided_and_keeps_its_number(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));
        $number = $invoice->number;

        $voided = $this->void->execute($invoice, 'Issued to the wrong customer');

        $this->assertSame(InvoiceStatus::Void, $voided->status);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Issued to the wrong customer', $voided->notes);
        // A gap in the series is answerable; a recycled number is not.
        $this->assertSame($number, $voided->number);
        $this->assertAmountDueAgrees($voided);
    }

    #[Test]
    public function a_draft_may_be_discarded(): void
    {
        $draft = Invoice::factory()->totalling(Money::of('10.000', 'KWD'))->create();

        $this->assertSame(InvoiceStatus::Void, $this->void->execute($draft)->status);
    }

    #[Test]
    public function voiding_twice_is_a_no_op(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));

        $first = $this->void->execute($invoice);
        $second = $this->void->execute($first);

        $this->assertTrue($first->voided_at->equalTo($second->voided_at));
        $this->assertSame(InvoiceStatus::Void, $second->status);
    }

    #[Test]
    public function a_paid_invoice_cannot_be_voided(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));
        $paid = $this->settle->execute($invoice, $this->capture($invoice, Money::of('10.000', 'KWD')))->invoice;

        try {
            $this->void->execute($paid);
            $this->fail('Expected a paid invoice to refuse being voided.');
        } catch (PaidInvoiceCannotBeVoidedException $e) {
            $this->assertSame('invoice.paid_cannot_be_voided', $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
            $this->assertSame(10_000, $e->context()['amount_paid_minor']);
        }

        $paid->refresh();
        $this->assertSame(InvoiceStatus::Paid, $paid->status);
        $this->assertNull($paid->voided_at);
    }

    #[Test]
    public function an_invoice_that_took_a_partial_payment_cannot_be_voided_either(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));
        $partly = $this->settle->execute($invoice, $this->capture($invoice, Money::of('4.000', 'KWD')))->invoice;

        /*
         * The state machine would allow open → void: it cannot see the money.
         * The action refuses because a void document would leave that payment
         * belonging to nothing.
         */
        $this->assertSame(InvoiceStatus::Open, $partly->status);
        $this->expectException(PaidInvoiceCannotBeVoidedException::class);

        $this->void->execute($partly);
    }

    #[Test]
    public function a_partial_refund_leaves_the_invoice_paid_and_a_full_one_refunds_it(): void
    {
        $invoice = $this->openInvoice(Money::of('30.000', 'KWD'));
        $paid = $this->settle->execute($invoice, $this->capture($invoice, Money::of('30.000', 'KWD')))->invoice;

        $partly = $this->refund->execute($paid, Money::of('10.000', 'KWD'));

        $this->assertSame(InvoiceStatus::Paid, $partly->status);
        $this->assertTrue($partly->amountRefunded()->equals(Money::of('10.000', 'KWD')));
        $this->assertTrue($partly->refundableAmount()->equals(Money::of('20.000', 'KWD')));
        // total − paid + refunded: the money that went back is owed again.
        $this->assertTrue($partly->amountDue()->equals(Money::of('10.000', 'KWD')));
        $this->assertAmountDueAgrees($partly);

        $fully = $this->refund->execute($partly, Money::of('20.000', 'KWD'));

        $this->assertSame(InvoiceStatus::Refunded, $fully->status);
        $this->assertTrue($fully->amountRefunded()->equals(Money::of('30.000', 'KWD')));
        $this->assertTrue($fully->refundableAmount()->isZero());
        $this->assertAmountDueAgrees($fully);
    }

    #[Test]
    public function a_refund_cannot_exceed_what_the_invoice_actually_took(): void
    {
        $invoice = $this->openInvoice(Money::of('30.000', 'KWD'));
        $paid = $this->settle->execute($invoice, $this->capture($invoice, Money::of('12.000', 'KWD')))->invoice;

        try {
            // The invoice was billed 30 but only ever received 12.
            $this->refund->execute($paid, Money::of('12.001', 'KWD'));
            $this->fail('Expected the refund to be refused.');
        } catch (InvoiceRefundExceedsPaymentException $e) {
            $this->assertSame('invoice.refund_exceeds_payment', $e->errorCode());
            $this->assertSame(12_000, $e->context()['refundable_minor']);
            $this->assertSame(12_001, $e->context()['requested_minor']);
        }

        $this->assertSame(0, $paid->refresh()->amount_refunded_minor);
        $this->assertAmountDueAgrees($paid);
    }

    #[Test]
    public function recording_the_same_refund_twice_reduces_the_invoice_once(): void
    {
        $invoice = $this->openInvoice(Money::of('30.000', 'KWD'));
        $capture = $this->capture($invoice, Money::of('30.000', 'KWD'));
        $paid = $this->settle->execute($invoice, $capture)->invoice;

        $refund = $this->refundRow($capture, Money::of('10.000', 'KWD'));

        $first = $this->refund->execute($paid, $refund->amount(), $refund);
        $second = $this->refund->execute($first, $refund->amount(), $refund);

        $this->assertTrue($second->amountRefunded()->equals(Money::of('10.000', 'KWD')));
        $this->assertSame($paid->id, $refund->refresh()->invoice_id);
        $this->assertAmountDueAgrees($second);
    }

    #[Test]
    public function a_refund_in_another_currency_is_refused(): void
    {
        $invoice = $this->openInvoice(Money::of('30.000', 'KWD'));
        $paid = $this->settle->execute($invoice, $this->capture($invoice, Money::of('30.000', 'KWD')))->invoice;

        $this->expectException(CurrencyMismatchException::class);

        $this->refund->execute($paid, Money::of('10.00', 'USD'));
    }

    #[Test]
    public function a_refunded_invoice_is_terminal(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));
        $paid = $this->settle->execute($invoice, $this->capture($invoice, Money::of('10.000', 'KWD')))->invoice;
        $refunded = $this->refund->execute($paid, Money::of('10.000', 'KWD'));

        $this->assertSame(InvoiceStatus::Refunded, $refunded->status);

        // Two independent refusals, and the order matters for the message a
        // support agent sees: the action objects to the money first, and the
        // state machine objects to the transition regardless.
        try {
            $this->void->execute($refunded);
            $this->fail('Expected a refunded invoice to refuse being voided.');
        } catch (PaidInvoiceCannotBeVoidedException $e) {
            $this->assertSame('invoice.paid_cannot_be_voided', $e->errorCode());
        }

        $this->expectException(IllegalStateTransitionException::class);

        app(TransitionInvoice::class)->execute($refunded, InvoiceStatus::Void);
    }

    #[Test]
    public function refunding_a_partly_paid_open_invoice_leaves_it_open(): void
    {
        $invoice = $this->openInvoice(Money::of('30.000', 'KWD'));
        $partly = $this->settle->execute($invoice, $this->capture($invoice, Money::of('10.000', 'KWD')))->invoice;

        $returned = $this->refund->execute($partly, Money::of('10.000', 'KWD'));

        // Giving back a deposit does not settle the invoice; it makes it
        // unpaid again, and it is still collectible.
        $this->assertSame(InvoiceStatus::Open, $returned->status);
        $this->assertTrue($returned->amountDue()->equals(Money::of('30.000', 'KWD')));
        $this->assertAmountDueAgrees($returned);
    }

    private function openInvoice(Money $total, ?Customer $customer = null): Invoice
    {
        $customer ??= Customer::factory()->create();

        return Invoice::factory()
            ->open()
            ->totalling($total)
            ->create(['customer_id' => $customer->id]);
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

        return $transaction;
    }

    private function refundRow(Transaction $capture, Money $amount): Refund
    {
        /** @var Refund $refund */
        $refund = Refund::query()->create([
            'transaction_id' => $capture->id,
            'amount_minor' => $amount->minorUnits(),
            'currency' => $amount->currency(),
            'status' => RefundStatus::Succeeded,
            'reason' => 'Service cancelled within the cooling-off period',
            'provider_reference' => 're_'.Str::random(16),
            'processed_at' => now(),
        ]);

        return $refund;
    }

    private function assertAmountDueAgrees(Invoice $invoice): void
    {
        /** @var object{total_minor: int|string, amount_paid_minor: int|string, amount_refunded_minor: int|string, amount_due_minor: int|string} $row */
        $row = DB::table('invoices')->where('id', $invoice->getKey())->firstOrFail();

        $this->assertSame(
            (int) $row->total_minor - (int) $row->amount_paid_minor + (int) $row->amount_refunded_minor,
            (int) $row->amount_due_minor,
        );
    }
}
