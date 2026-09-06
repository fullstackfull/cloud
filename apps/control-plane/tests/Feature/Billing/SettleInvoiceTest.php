<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\Actions\TransitionInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceNotPayableException;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceOverpaymentRefusedException;
use Lynomia\Modules\Billing\Domain\Exceptions\UnsettleablePaymentException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\Exceptions\CurrencyMismatchException;
use Lynomia\Modules\Shared\Domain\Exceptions\IllegalStateTransitionException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SettleInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private SettleInvoice $settle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settle = app(SettleInvoice::class);
        config()->set('billing.credit_overpayment_to_wallet', true);
    }

    #[Test]
    public function partial_payments_accumulate_and_the_invoice_is_paid_exactly_at_zero_due(): void
    {
        $invoice = $this->openInvoice(Money::of('30.000', 'KWD'));

        $first = $this->settle->execute($invoice, $this->capture($invoice, Money::of('10.000', 'KWD')));

        $this->assertTrue($first->applied->equals(Money::of('10.000', 'KWD')));
        $this->assertSame(InvoiceStatus::Open, $first->invoice->status);
        $this->assertTrue($first->invoice->amountDue()->equals(Money::of('20.000', 'KWD')));
        $this->assertNull($first->invoice->paid_at);
        $this->assertAmountDueAgrees($first->invoice);

        $second = $this->settle->execute($first->invoice, $this->capture($invoice, Money::of('19.999', 'KWD')));

        // One fil short is still open: "nearly paid" is not a state.
        $this->assertSame(InvoiceStatus::Open, $second->invoice->status);
        $this->assertTrue($second->invoice->amountDue()->equals(Money::ofMinor(1, 'KWD')));
        $this->assertAmountDueAgrees($second->invoice);

        $third = $this->settle->execute($second->invoice, $this->capture($invoice, Money::ofMinor(1, 'KWD')));

        $this->assertSame(InvoiceStatus::Paid, $third->invoice->status);
        $this->assertTrue($third->invoice->amountDue()->isZero());
        $this->assertTrue($third->invoice->amountPaid()->equals(Money::of('30.000', 'KWD')));
        $this->assertNotNull($third->invoice->paid_at);
        $this->assertAmountDueAgrees($third->invoice);
    }

    #[Test]
    public function a_two_decimal_currency_settles_in_exact_minor_units(): void
    {
        // The three-decimal case is covered above; this is the same arithmetic
        // in a currency whose minor unit is a hundredth, to pin that nothing
        // in settlement assumes the platform's default fils.
        $customer = Customer::factory()->create(['currency' => 'USD']);
        $invoice = $this->openInvoice(Money::of('42.57', 'USD'), $customer);

        $first = $this->settle->execute($invoice, $this->capture($invoice, Money::of('20.00', 'USD')));

        $this->assertSame('22.57', $first->invoice->amountDue()->toDecimalString());
        $this->assertSame(InvoiceStatus::Open, $first->invoice->status);

        $second = $this->settle->execute($first->invoice, $this->capture($invoice, Money::of('22.57', 'USD')));

        $this->assertSame(InvoiceStatus::Paid, $second->invoice->status);
        $this->assertSame('0.00', $second->invoice->amountDue()->toDecimalString());
        $this->assertSame(4_257, $second->invoice->amount_paid_minor);
        $this->assertSame('USD', $second->invoice->amountPaid()->currency());
        $this->assertAmountDueAgrees($second->invoice);
    }

    #[Test]
    public function settling_the_same_transaction_twice_applies_it_once(): void
    {
        $invoice = $this->openInvoice(Money::of('30.000', 'KWD'));
        $capture = $this->capture($invoice, Money::of('30.000', 'KWD'));

        $first = $this->settle->execute($invoice, $capture);
        // The webhook the provider redelivers carries the same transaction.
        $second = $this->settle->execute($first->invoice, $capture);

        $this->assertTrue($first->applied->equals(Money::of('30.000', 'KWD')));
        $this->assertTrue($second->applied->isZero());
        $this->assertTrue($second->movedNothing());

        $this->assertSame(30_000, $second->invoice->amount_paid_minor);
        $this->assertSame(InvoiceStatus::Paid, $second->invoice->status);
        $this->assertTrue($first->invoice->paid_at->equalTo($second->invoice->paid_at));
        $this->assertAmountDueAgrees($second->invoice);
    }

    #[Test]
    public function an_overpayment_is_credited_to_the_customers_wallet(): void
    {
        $customer = Customer::factory()->create();
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'), $customer);
        $capture = $this->capture($invoice, Money::of('12.500', 'KWD'));

        $settlement = $this->settle->execute($invoice, $capture);

        /*
         * The money has already been captured by the provider, so refusing it
         * would leave a payment attached to nothing. It is applied up to the
         * total and the rest becomes stored value the customer can spend.
         */
        $this->assertTrue($settlement->applied->equals(Money::of('10.000', 'KWD')));
        $this->assertTrue($settlement->creditedToWallet->equals(Money::of('2.500', 'KWD')));
        $this->assertSame(InvoiceStatus::Paid, $settlement->invoice->status);
        $this->assertTrue($settlement->invoice->amountDue()->isZero());
        $this->assertAmountDueAgrees($settlement->invoice);

        $wallet = app(WalletLedger::class)->walletFor($customer, 'KWD');
        $this->assertTrue($wallet->balance()->equals(Money::of('2.500', 'KWD')));

        // A redelivery must not credit the surplus a second time.
        $replay = $this->settle->execute($settlement->invoice, $capture);

        $this->assertTrue($replay->creditedToWallet->isZero());
        $this->assertTrue(app(WalletLedger::class)->walletFor($customer, 'KWD')->balance()->equals(Money::of('2.500', 'KWD')));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
    }

    #[Test]
    public function a_later_payment_on_an_overpaid_invoice_credits_only_what_it_added(): void
    {
        $customer = Customer::factory()->create();
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'), $customer);

        $first = $this->settle->execute($invoice, $this->capture($invoice, Money::of('12.000', 'KWD')));
        $second = $this->settle->execute($first->invoice, $this->capture($invoice, Money::of('3.000', 'KWD')));

        $this->assertTrue($first->creditedToWallet->equals(Money::of('2.000', 'KWD')));
        // Not 5.000: the overhang the first payment already credited is not
        // credited again by the second.
        $this->assertTrue($second->creditedToWallet->equals(Money::of('3.000', 'KWD')));
        $this->assertTrue($second->applied->isZero());

        $wallet = app(WalletLedger::class)->walletFor($customer, 'KWD');
        $this->assertTrue($wallet->balance()->equals(Money::of('5.000', 'KWD')));
        $this->assertSame(10_000, $second->invoice->amount_paid_minor);
        $this->assertAmountDueAgrees($second->invoice);
    }

    #[Test]
    public function an_overpayment_is_refused_outright_when_the_wallet_policy_is_turned_off(): void
    {
        config()->set('billing.credit_overpayment_to_wallet', false);

        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));
        $capture = $this->capture($invoice, Money::of('12.500', 'KWD'));

        try {
            $this->settle->execute($invoice, $capture);
            $this->fail('Expected the overpayment to be refused.');
        } catch (InvoiceOverpaymentRefusedException $e) {
            $this->assertSame('invoice.overpayment_refused', $e->errorCode());
            $this->assertSame(10_000, $e->context()['due_minor']);
            $this->assertSame(12_500, $e->context()['offered_minor']);
        }

        // The refusal happens before anything is written, so neither the
        // invoice nor the transaction records a settlement that did not occur.
        $invoice->refresh();
        $this->assertSame(0, $invoice->amount_paid_minor);
        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertNull($capture->refresh()->invoice_id);
        $this->assertSame(0, DB::table('wallet_transactions')->count());
    }

    #[Test]
    public function a_draft_or_void_invoice_cannot_be_settled(): void
    {
        $draft = Invoice::factory()->totalling(Money::of('10.000', 'KWD'))->create();
        $void = Invoice::factory()->totalling(Money::of('10.000', 'KWD'))->create(['status' => InvoiceStatus::Void]);

        foreach ([$draft, $void] as $invoice) {
            try {
                $this->settle->execute($invoice, $this->capture($invoice, Money::of('10.000', 'KWD')));
                $this->fail(sprintf('Expected a %s invoice to refuse a payment.', $invoice->status->value));
            } catch (InvoiceNotPayableException $e) {
                $this->assertSame('invoice.not_payable', $e->errorCode());
                $this->assertSame(409, $e->httpStatus());
                $this->assertSame($invoice->status->value, $e->context()['status']);
            }
        }
    }

    #[Test]
    public function a_payment_in_another_currency_is_never_applied(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));

        $this->expectException(CurrencyMismatchException::class);

        $this->settle->execute($invoice, $this->capture($invoice, Money::of('10.00', 'USD')));
    }

    #[Test]
    public function a_transaction_that_is_not_a_captured_charge_is_refused(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));

        $pending = $this->capture($invoice, Money::of('10.000', 'KWD'));
        $pending->status = TransactionStatus::Pending;
        $pending->save();

        try {
            $this->settle->execute($invoice, $pending);
            $this->fail('Expected a pending transaction to be refused.');
        } catch (UnsettleablePaymentException $e) {
            $this->assertSame('invoice.payment_not_settleable', $e->errorCode());
            $this->assertSame('pending', $e->context()['status']);
        }

        $this->assertSame(0, $invoice->refresh()->amount_paid_minor);
    }

    #[Test]
    public function one_capture_cannot_pay_two_invoices(): void
    {
        $customer = Customer::factory()->create();
        $first = $this->openInvoice(Money::of('10.000', 'KWD'), $customer);
        $second = $this->openInvoice(Money::of('10.000', 'KWD'), $customer);

        $capture = $this->capture($first, Money::of('10.000', 'KWD'));
        $this->settle->execute($first, $capture);

        $this->expectException(UnsettleablePaymentException::class);

        $this->settle->execute($second, $capture);
    }

    #[Test]
    public function a_payment_belonging_to_another_customer_is_refused(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));

        $stranger = $this->capture($invoice, Money::of('10.000', 'KWD'));
        $stranger->customer_id = Customer::factory()->create()->id;
        $stranger->save();

        $this->expectException(UnsettleablePaymentException::class);

        $this->settle->execute($invoice, $stranger);
    }

    #[Test]
    public function a_paid_invoice_can_never_return_to_open(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));
        $settled = $this->settle->execute($invoice, $this->capture($invoice, Money::of('10.000', 'KWD')))->invoice;

        $this->assertSame(InvoiceStatus::Paid, $settled->status);

        try {
            app(TransitionInvoice::class)->execute($settled, InvoiceStatus::Open);
            $this->fail('Expected a paid invoice to refuse a return to open.');
        } catch (IllegalStateTransitionException $e) {
            $this->assertSame('Invoice', $e->context()['subject']);
            $this->assertSame('paid', $e->context()['from']);
            $this->assertSame('open', $e->context()['to']);
        }

        $this->assertSame(InvoiceStatus::Paid, $settled->refresh()->status);
    }

    #[Test]
    public function the_derived_amount_due_column_cannot_be_assigned(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));

        /*
         * PostgreSQL would reject the write anyway, but it would reject it in
         * the middle of a settlement transaction. Failing at the assignment
         * names the mistake where it was made.
         */
        $this->expectException(LogicException::class);

        $invoice->amount_due_minor = 0;
    }

    #[Test]
    public function a_payment_replacing_a_refunded_one_settles_the_invoice_rather_than_the_wallet(): void
    {
        $customer = Customer::factory()->create();
        $invoice = $this->openInvoice(Money::of('30.000', 'KWD'), $customer);

        $deposit = $this->capture($invoice, Money::of('10.000', 'KWD'));
        $partly = $this->settle->execute($invoice, $deposit)->invoice;

        // The deposit goes back to the customer: the invoice is unpaid again
        // and owes the whole thirty.
        $returned = app(RecordInvoiceRefund::class)->execute($partly, Money::of('10.000', 'KWD'));

        $this->assertSame(InvoiceStatus::Open, $returned->status);
        $this->assertTrue($returned->amountDue()->equals(Money::of('30.000', 'KWD')));

        $settled = $this->settle->execute($returned, $this->capture($invoice, Money::of('30.000', 'KWD')));

        /*
         * Refunded money is owed again, so the invoice can absorb it again. A
         * settlement that measured this payment against the total alone would
         * see the returned deposit as an overpayment, divert thirty fils short
         * of the whole payment into the wallet and leave a fully paid invoice
         * sitting in dunning.
         */
        $this->assertTrue($settled->creditedToWallet->isZero());
        $this->assertTrue($settled->applied->equals(Money::of('30.000', 'KWD')));
        $this->assertSame(InvoiceStatus::Paid, $settled->invoice->status);
        $this->assertTrue($settled->invoice->amountDue()->isZero());
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertAmountDueAgrees($settled->invoice);
    }

    #[Test]
    public function a_redelivered_capture_for_an_invoice_that_has_since_been_refunded_is_a_no_op(): void
    {
        $customer = Customer::factory()->create();
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'), $customer);
        $capture = $this->capture($invoice, Money::of('10.000', 'KWD'));

        $paid = $this->settle->execute($invoice, $capture)->invoice;
        $refunded = app(RecordInvoiceRefund::class)->execute($paid, Money::of('10.000', 'KWD'));

        $this->assertSame(InvoiceStatus::Refunded, $refunded->status);

        /*
         * Providers redeliver a capture for days, and a refund can easily be
         * issued inside that window. The payment this webhook carries is
         * already recorded on the invoice, so the redelivery has to change
         * nothing — not fail the handler and be retried forever.
         */
        $replay = $this->settle->execute($refunded, $capture);

        $this->assertTrue($replay->movedNothing());
        $this->assertSame(InvoiceStatus::Refunded, $replay->invoice->status);
        $this->assertSame(10_000, $replay->invoice->amount_paid_minor);
        $this->assertSame(0, DB::table('wallet_transactions')->count());
        $this->assertAmountDueAgrees($replay->invoice);
    }

    #[Test]
    public function a_surplus_already_credited_to_the_wallet_is_never_collected_a_second_time(): void
    {
        $customer = Customer::factory()->create();
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'), $customer);

        // Overpaid by two: ten settles the invoice, two becomes stored value.
        $overpaid = $this->settle->execute($invoice, $this->capture($invoice, Money::of('12.000', 'KWD')))->invoice;

        // Part of the bill is then returned, so the invoice owes four again.
        $partly = app(RecordInvoiceRefund::class)->execute($overpaid, Money::of('4.000', 'KWD'));
        $this->assertTrue($partly->amountDue()->equals(Money::of('4.000', 'KWD')));

        $settled = $this->settle->execute($partly, $this->capture($invoice, Money::of('4.000', 'KWD')));

        /*
         * The refund raises what the invoice can absorb, which is what lets
         * the four settle it. The two fils sitting in the wallet must not ride
         * along into that ceiling: counted again they would be credited a
         * second time, giving the customer stored value they never paid for.
         */
        $this->assertTrue($settled->invoice->amountDue()->isZero());
        $this->assertTrue($settled->creditedToWallet->isZero());
        $this->assertSame(14_000, $settled->invoice->amount_paid_minor);
        $this->assertAmountDueAgrees($settled->invoice);

        // 16 captured, 4 returned, 2 in the wallet: the invoice kept its ten.
        $wallet = app(WalletLedger::class)->walletFor($customer, 'KWD');
        $this->assertTrue($wallet->balance()->equals(Money::of('2.000', 'KWD')));
        $this->assertSame(1, DB::table('wallet_transactions')->count());
        $this->assertTrue(
            $settled->invoice->amountPaid()->minus($settled->invoice->amountRefunded())
                ->equals($settled->invoice->total()),
        );
    }

    #[Test]
    public function a_capture_the_payments_side_attached_before_calling_is_still_applied(): void
    {
        $invoice = $this->openInvoice(Money::of('10.000', 'KWD'));

        // The documented alternative wiring: Payments owns the link and sets
        // invoice_id itself, then asks for the settlement.
        $capture = $this->capture($invoice, Money::of('10.000', 'KWD'));
        $capture->invoice_id = $invoice->id;
        $capture->save();

        $settlement = $this->settle->execute($invoice, $capture);

        $this->assertTrue($settlement->applied->equals(Money::of('10.000', 'KWD')));
        $this->assertSame(InvoiceStatus::Paid, $settlement->invoice->status);
        $this->assertTrue($settlement->invoice->amountDue()->isZero());
        $this->assertTrue($this->settle->execute($settlement->invoice, $capture)->movedNothing());
        $this->assertAmountDueAgrees($settlement->invoice);
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
