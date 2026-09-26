<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Billing\Application\Actions\CreditWhatACancelledOrderPaid;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
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
use Lynomia\Modules\Orders\Application\Listeners\FulfilOrderOnSettlement;
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
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
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
 *      "Paid" is the invoice's word here, not the order's: between
 *      SettleInvoice and the queued fulfilment the order still reads
 *      pending_payment, and cancelling it there used to keep the money and
 *      deliver nothing (F-05, as the re-audit after round two measured it).
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

    /*
     * Settlement and fulfilment are two different moments in production.
     * SettleInvoice marks the invoice paid and announces it; the order only
     * leaves `pending_payment` when the queued FulfilOrderOnSettlement job
     * runs on the payments worker. phpunit.xml runs the queue synchronously,
     * which closes that window inside a test, so every case here fakes the
     * queue first and runs the fulfilment job by hand, afterwards, the way a
     * worker a few seconds behind would.
     */

    #[Test]
    public function an_order_that_has_been_paid_cannot_be_cancelled(): void
    {
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        Queue::fake();

        $this->settle($invoice, $customer);

        // The window is open: the money is taken and the order does not know.
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertNull($order->paid_at, 'Precondition: fulfilment has not run yet, so the window is open.');

        try {
            app(CancelOrder::class)->execute($order->refresh());
            $this->fail('A paid order was cancelled. That is a refund, not a cancellation.');
        } catch (OrderCannotBeCancelledException $e) {
            // The refusal names the money, not the workflow: a customer whose
            // order is paid needs to be pointed at a refund.
            $this->assertSame('order.already_paid', $e->errorCode());
        }

        // And the invoice the customer paid is untouched by the attempt.
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertFalse(CancelOrder::isCancellable($order), 'The portal must not offer a button the action refuses.');

        // The worker catches up, and the order it finds is one it can fulfil.
        $this->runQueuedFulfilment();

        $this->assertNotNull($order->refresh()->paid_at);
        $this->assertNotSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(1, Service::query()->where('order_id', $order->getKey())->count());
    }

    #[Test]
    public function a_customer_who_pays_from_credit_cannot_cancel_before_the_payments_worker_runs(): void
    {
        /*
         * The re-audit's own path, driven only through customer routes: a
         * wallet payment settles at once, and the cancel request arrives before
         * the payments worker has picked the fulfilment up.
         */
        [$customer, $user] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        $ledger = app(WalletLedger::class);
        $ledger->credit(
            wallet: $ledger->walletFor($customer, 'KWD'),
            amount: Money::ofMinor($invoice->total_minor, 'KWD'),
            kind: WalletTransactionKind::Topup,
            description: 'Top-up by card ending 4242',
        );

        Queue::fake();

        $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => 'f05-wallet-then-cancel'])
            ->postJson('/api/v1/invoices/'.$invoice->getKey().'/wallet-credit')
            ->assertOk()
            ->assertJsonPath('data.status', InvoiceStatus::Paid->value);

        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);

        $this->actingAs($user)
            ->getJson('/api/v1/orders/'.$order->getKey())
            ->assertOk()
            ->assertJsonPath('data.is_cancellable', false);

        $this->actingAs($user)
            ->postJson('/api/v1/orders/'.$order->getKey().'/cancel')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'order.already_paid');

        $this->runQueuedFulfilment();

        $this->assertNotSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(1, Service::query()->where('order_id', $order->getKey())->count());
        $this->assertSame(0, $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits());
    }

    #[Test]
    public function a_partly_paid_order_is_refused_as_paid_and_its_invoice_is_left_alone(): void
    {
        /*
         * Money that has been applied to the document is money the customer
         * handed over. Voiding the invoice would say it never happened, and
         * cancelling the order around it would leave it attached to nothing.
         */
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        $this->settle($invoice, $customer, $invoice->total_minor - 1_000);

        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
        $this->assertGreaterThan(0, $invoice->amount_paid_minor);

        try {
            app(CancelOrder::class)->execute($order->refresh());
            $this->fail('An order with money applied to its invoice was cancelled.');
        } catch (OrderCannotBeCancelledException $e) {
            $this->assertSame('order.already_paid', $e->errorCode());
        }

        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(InvoiceStatus::Open, $invoice->refresh()->status);
    }

    #[Test]
    public function fulfilment_that_finds_a_cancelled_order_hands_the_money_back_once(): void
    {
        /*
         * CancelOrder now refuses once money is on the invoice, under the
         * invoice's lock, so this row should not be reachable through it. A
         * row written before that refusal existed is, and the fulfilment job
         * must not answer it with five retries and a permanent failure while
         * the customer's money sits on a paid invoice.
         */
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        Queue::fake();

        $this->settle($invoice, $customer);

        // Written as the pre-fix code wrote it: the order cancelled beside a
        // paid invoice.
        $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();

        $ledger = app(WalletLedger::class);

        // Retried the way the queue retries it; none of them may throw.
        $this->runQueuedFulfilment();
        $this->runQueuedFulfilment();

        $this->assertSame(
            $invoice->total_minor,
            $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits(),
            'The money taken for an order that was cancelled belongs to the customer, once.',
        );
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(0, Service::query()->where('order_id', $order->getKey())->count());
        $this->assertSame(0, Subscription::query()->where('order_id', $order->getKey())->count());
    }

    #[Test]
    public function the_cancel_decides_on_the_invoice_under_its_lock_before_it_moves_the_order(): void
    {
        /*
         * The refusal above is only as good as the read it makes. Without the
         * invoice's row lock, a settlement committing between the read and
         * the order's move is invisible, and the window this file is about
         * reopens under concurrency. A single-process test cannot interleave
         * two transactions, so this holds the mechanism: the cancel reads the
         * invoice FOR UPDATE — the lock SettleInvoice takes before it applies
         * money — and does so before the order is written.
         */
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);

        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        app(CancelOrder::class)->execute($order);

        $lockedInvoiceRead = null;
        $orderWrite = null;

        foreach ($statements as $i => $sql) {
            if ($lockedInvoiceRead === null && str_contains($sql, 'from "invoices"') && str_contains($sql, 'for update')) {
                $lockedInvoiceRead = $i;
            }

            if ($orderWrite === null && str_starts_with($sql, 'update "orders"')) {
                $orderWrite = $i;
            }
        }

        $this->assertNotNull($lockedInvoiceRead, 'The cancel never read the order\'s invoice under a row lock.');
        $this->assertNotNull($orderWrite, 'Precondition: the order was written.');
        $this->assertLessThan($orderWrite, $lockedInvoiceRead, 'The invoice was locked only after the order had already moved.');
    }

    #[Test]
    public function a_surplus_already_in_the_wallet_is_not_credited_a_second_time(): void
    {
        /*
         * The verifier's first B2 probe: 2.000 captured on a 1.500 invoice.
         * SettleInvoice sends the 0.500 surplus to the wallet at settlement,
         * so a cancelled order owes back 1.500 more — not the 2.000 capture.
         */
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        Queue::fake();

        $this->settle($invoice, $customer, $invoice->total_minor + 500);

        $ledger = app(WalletLedger::class);
        $this->assertSame(500, $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits(), 'Precondition: the surplus went to the wallet at settlement.');

        $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();

        $this->runQueuedFulfilment();
        $this->runQueuedFulfilment();

        $this->assertSame(
            $invoice->total_minor + 500,
            $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits(),
            'The customer handed over total + 500 and must hold exactly that, not more.',
        );
    }

    #[Test]
    public function money_already_refunded_on_the_invoice_is_not_credited_again(): void
    {
        /*
         * The verifier's second B2 probe: the paid invoice of a cancelled
         * order is refunded in full by an operator, and the failed job is
         * retried afterwards. The refund already gave the money back.
         */
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        Queue::fake();

        $this->settle($invoice, $customer);
        $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();

        app(RecordInvoiceRefund::class)->execute($invoice->refresh(), Money::ofMinor($invoice->total_minor, 'KWD'));

        $this->runQueuedFulfilment();

        $ledger = app(WalletLedger::class);
        $this->assertSame(0, $ledger->balance($ledger->walletFor($customer, 'KWD'))->minorUnits());

        // And the action reports what it did: nothing, on this run and the next.
        $this->assertSame(0, app(CreditWhatACancelledOrderPaid::class)->execute((string) $order->getKey()));
    }

    #[Test]
    public function the_credit_reports_what_it_moved_once_and_nothing_on_a_retry(): void
    {
        [$customer] = $this->accountWithOwner();
        $order = $this->placedOrder($customer);
        $invoice = $this->invoiceFor($order);

        Queue::fake();

        $this->settle($invoice, $customer);
        $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();

        $credit = app(CreditWhatACancelledOrderPaid::class);

        $this->assertSame($invoice->total_minor, $credit->execute((string) $order->getKey()));
        $this->assertSame(0, $credit->execute((string) $order->getKey()));
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

    private function settle(Invoice $invoice, Customer $customer, ?int $amountMinor = null): void
    {
        $capture = Transaction::factory()->create([
            'customer_id' => $customer->getKey(),
            'invoice_id' => $invoice->getKey(),
            'amount_minor' => $amountMinor ?? $invoice->total_minor,
            'currency' => $invoice->currency,
        ]);

        app(SettleInvoice::class)->execute($invoice, $capture);
    }

    /**
     * What the payments worker does when it reaches the settlement: every
     * FulfilOrderOnSettlement the faked queue is holding, run as the queue
     * would run it.
     */
    private function runQueuedFulfilment(): void
    {
        $jobs = Queue::pushed(
            CallQueuedListener::class,
            static fn (CallQueuedListener $job): bool => $job->class === FulfilOrderOnSettlement::class,
        );

        $this->assertNotEmpty($jobs, 'Precondition: fulfilment was queued, not run inline.');

        foreach ($jobs as $job) {
            app(FulfilOrderOnSettlement::class)->handle($job->data[0]);
        }
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
