<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\Listeners\SettleInvoiceOnPaymentCaptured;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Exceptions\InvoiceNotPayableException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Catalog\Domain\Enums\BillingPeriod;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Orders\Application\Actions\CancelOrder;
use Lynomia\Modules\Orders\Application\Actions\PlaceOrder;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutLine;
use Lynomia\Modules\Orders\Application\DTOs\CheckoutRequest;
use Lynomia\Modules\Orders\Domain\Enums\OrderStatus;
use Lynomia\Modules\Orders\Domain\Exceptions\OrderCannotBeCancelledException;
use Lynomia\Modules\Orders\Infrastructure\Models\Order;
use Lynomia\Modules\Payments\Application\Actions\RecordPaymentCapture;
use Lynomia\Modules\Payments\Application\Actions\StartInvoicePayment;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Domain\Services\WalletLedger;
use Lynomia\Modules\Wallet\Infrastructure\Models\Wallet;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * A cancelled order must not go on collecting money.
 *
 * ---------------------------------------------------------------------------
 * What was wrong
 * ---------------------------------------------------------------------------
 *
 * CancelOrder moves the order to CANCELLED and touches nothing else. Its
 * docblock says "Nothing is released by hand", which is true of stock and of
 * a coupon hold — both are counted from live orders — and silently untrue of
 * the invoice, which stays OPEN. OPEN is the only collectible status, so the
 * customer's pay button goes on working after they have cancelled.
 *
 * Downstream of that, CANCELLED is terminal: `allowedNext()` returns an empty
 * list. So a capture that lands afterwards settles the invoice, announces
 * OrderFinanciallySettled, and FulfilOrderOnSettlement tries to move a
 * cancelled order to PAID — which the state machine refuses. That refusal
 * happens inside a queued job with five tries, so the outcome is five retries
 * and a permanently failed job: the money is captured, the invoice says paid,
 * nothing is delivered, and nothing is given back.
 *
 * ---------------------------------------------------------------------------
 * The three timelines
 * ---------------------------------------------------------------------------
 *
 * They are different and the tests keep them apart:
 *
 *   A  cancellation wins  — the invoice stops being collectible, and a payment
 *      cannot even be started, so no provider intent is ever created.
 *   B  settlement wins    — the order is genuinely paid, cancellation refuses,
 *      and fulfilment proceeds. A paid order is a refund, not a cancellation.
 *   C  a capture arrives after cancellation won — the money is real and at the
 *      provider. It goes to the customer's wallet, which is the mechanism this
 *      domain already uses for a capture an invoice cannot absorb.
 */
final class ACancelledOrderCollectsNoMoneyTest extends OrdersApiTestCase
{
    private const REFERENCE = 'pi_cancelled_order_1';

    // ---- case A: cancellation wins ----------------------------------------

    #[Test]
    public function cancelling_an_unpaid_order_stops_its_invoice_being_collectible(): void
    {
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        $this->assertSame(InvoiceStatus::Open, $invoice->status);
        $this->assertTrue($invoice->status->isCollectible(), 'Precondition: the invoice starts collectible.');

        app(CancelOrder::class)->execute($order);

        $settled = $invoice->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertFalse(
            $settled->status->isCollectible(),
            'A cancelled order must not leave a document the customer can still pay.',
        );
        $this->assertSame(InvoiceStatus::Void, $settled->status);
    }

    #[Test]
    public function a_cancelled_orders_invoice_cannot_open_a_payment(): void
    {
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        app(CancelOrder::class)->execute($order);

        try {
            app(StartInvoicePayment::class)->execute($invoice->refresh());
            $this->fail('A payment was started for an order the customer had cancelled.');
        } catch (InvoiceNotPayableException $e) {
            $this->assertSame('invoice.not_payable', $e->errorCode());
        }

        // And the provider was never asked. An intent created here would be a
        // real authorisation hold on a real card for an order that is over.
        $this->assertSame(
            0,
            Transaction::query()->where('customer_id', $customer->getKey())->count(),
            'No provider intent may be created after cancellation has won.',
        );
    }

    #[Test]
    public function cancelling_before_capture_provisions_nothing(): void
    {
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);

        app(CancelOrder::class)->execute($order);

        $this->assertSame(0, Service::query()->where('customer_id', $customer->getKey())->count());
    }

    // ---- case B: settlement wins ------------------------------------------

    #[Test]
    public function an_order_that_has_been_paid_cannot_be_cancelled(): void
    {
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        $this->settle($invoice, $customer);

        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);

        try {
            app(CancelOrder::class)->execute($order->refresh());
            $this->fail('A paid order was cancelled. That is a refund, not a cancellation.');
        } catch (OrderCannotBeCancelledException $e) {
            // The refusal names the money, not the workflow: a customer whose
            // order is paid needs to be pointed at a refund.
            $this->assertStringContainsString('order.', $e->errorCode());
        }

        // And the invoice the customer paid is untouched by the attempt.
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
    }

    // ---- case C: the capture lands after cancellation ---------------------

    #[Test]
    public function money_captured_after_cancellation_reaches_the_customers_wallet(): void
    {
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        // The customer opened the payment page, then cancelled.
        app(StartInvoicePayment::class)->execute($invoice);
        app(CancelOrder::class)->execute($order);

        $ledger = app(WalletLedger::class);
        $before = $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();

        // The provider reports a genuine capture afterwards.
        $this->capture($customer, $invoice);

        $after = $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();

        $this->assertSame(
            $before + $invoice->total_minor,
            $after,
            'Captured money that no invoice can absorb belongs to the customer, not to nobody.',
        );

        // The cancelled order stays cancelled. Nothing restores it, and
        // nothing was built.
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(0, Service::query()->where('customer_id', $customer->getKey())->count());
        $this->assertSame(InvoiceStatus::Void, $invoice->refresh()->status);
    }

    #[Test]
    public function replaying_the_same_late_capture_compensates_once(): void
    {
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        app(StartInvoicePayment::class)->execute($invoice);
        app(CancelOrder::class)->execute($order);

        $ledger = app(WalletLedger::class);
        $before = $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();

        // A redelivered webhook, five times.
        for ($i = 0; $i < 5; $i++) {
            $this->capture($customer, $invoice);
        }

        /*
         * And then the part the loop above cannot reach.
         *
         * RecordPaymentCapture converges on one transaction per provider
         * reference, so replaying the webhook never gets as far as the
         * compensation a second time — a proof that stopped there would be
         * proving *that* dedup and saying nothing about this one. The listener
         * is queued with tries=5, so the case that actually repeats the credit
         * is the job being retried with the same event: a settlement that
         * failed after the wallet was credited, a worker killed mid-handle, a
         * redelivery the queue could not confirm.
         */
        /** @var Transaction $capture */
        $capture = Transaction::query()->where('provider_reference', self::REFERENCE)->sole();

        $event = new PaymentCaptured(
            transactionId: (string) $capture->getKey(),
            customerId: (string) $customer->getKey(),
            invoiceId: (string) $invoice->getKey(),
            provider: 'fake',
            providerReference: self::REFERENCE,
            amount: Money::ofMinor($invoice->total_minor, $invoice->currency),
            capturedAt: CarbonImmutable::now(),
        );

        for ($i = 0; $i < 3; $i++) {
            app(SettleInvoiceOnPaymentCaptured::class)->handle($event);
        }

        $this->assertSame(
            $before + $invoice->total_minor,
            $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits(),
            'One payment is one compensation, however many times the webhook or its job is replayed.',
        );

        // The positive control on the balance: exactly one credit exists, so
        // the figure above is one compensation rather than several that
        // happen to cancel out.
        $this->assertSame(
            1,
            WalletTransaction::query()
                ->whereIn('wallet_id', Wallet::query()->where('customer_id', $customer->getKey())->select('id'))
                ->count(),
            'The compensation was written more than once; its idempotency key is not holding.',
        );
    }

    #[Test]
    public function one_customers_compensation_never_reaches_another(): void
    {
        [$alice] = $this->accountWithOwner();
        [$bob] = $this->accountWithOwner();

        $order = $this->placedOrder($alice);
        $invoice = $this->invoiceFor($order);

        app(StartInvoicePayment::class)->execute($invoice);
        app(CancelOrder::class)->execute($order);
        $this->capture($alice, $invoice);

        $ledger = app(WalletLedger::class);

        $this->assertTrue($ledger->balance($ledger->walletFor($alice, 'KWD'))->isPositive());
        $this->assertSame(
            0,
            $ledger->balance($ledger->walletFor($bob, 'KWD'))->minorUnits(),
            "Another customer's cancelled order is none of Bob's money.",
        );
    }

    #[Test]
    public function no_money_is_lost_or_counted_twice_across_the_late_capture(): void
    {
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        app(StartInvoicePayment::class)->execute($invoice);
        app(CancelOrder::class)->execute($order);
        $this->capture($customer, $invoice);

        $captured = (int) Transaction::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', 'succeeded')
            ->sum('amount_minor');

        $ledger = app(WalletLedger::class);
        $credited = $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits();
        $applied = (int) $invoice->refresh()->amount_paid_minor;

        $this->assertSame(
            $captured,
            $applied + $credited,
            'Every captured fils is either applied to a document or held for the customer.',
        );
        $this->assertSame('KWD', $invoice->currency);
    }

    // ---- fixtures ---------------------------------------------------------

    private function placedOrder(Customer $customer): Order
    {
        $plan = $this->publishedPlan();

        return app(PlaceOrder::class)->execute($customer, new CheckoutRequest(
            lines: [new CheckoutLine($plan->id, 1)],
            billingPeriod: BillingPeriod::Monthly,
            couponCode: null,
            idempotencyKey: null,
        ));
    }

    private function invoiceFor(Order $order): Invoice
    {
        return Invoice::query()->where('order_id', $order->getKey())->sole();
    }

    private function settle(Invoice $invoice, Customer $customer): void
    {
        $capture = Transaction::factory()->create([
            'customer_id' => $customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);

        app(SettleInvoice::class)->execute($invoice, $capture);
    }

    private function capture(Customer $customer, Invoice $invoice): void
    {
        app(RecordPaymentCapture::class)->execute('fake', new ProviderEvent(
            providerEventId: 'evt_'.self::REFERENCE,
            type: 'payment.succeeded',
            kind: ProviderEventKind::PaymentSucceeded,
            amount: Money::ofMinor($invoice->total_minor, $invoice->currency),
            currency: $invoice->currency,
            providerReference: self::REFERENCE,
            payload: ['data' => ['reference' => self::REFERENCE]],
            metadata: ['customer_id' => $customer->getKey(), 'invoice_id' => (string) $invoice->getKey()],
        ));
    }
}
