<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Application\Actions\RecordInvoiceRefund;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\Listeners\RecordRefundAgainstTheInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\SubscriptionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Events\PaymentFailed;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Subscriptions\Application\Actions\AdvanceDunning;
use Lynomia\Modules\Subscriptions\Application\Listeners\StartDunningOnFailedPayment;
use Lynomia\Modules\Subscriptions\Infrastructure\Models\Subscription;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The four kinds of money work F-08 names — settlement, refund, dunning and
 * renewal — each survive being run twice, and each decides "have I already
 * done this?" from a row it holds locked.
 *
 * F-08 made the queue re-deliver work while the first delivery was still
 * running. Fixing the clocks stops the queue doing that, but it is not the
 * only source of a second delivery — a provider redelivers a webhook, a
 * retried job runs after a crash, an operator replays `failed_jobs` — so the
 * work itself has to converge. The property that makes it converge under
 * *overlap*, not only under sequential redelivery, is that the guard is read
 * inside the same transaction, from a row locked `FOR UPDATE`, before anything
 * is written: two overlapping executions then serialise on that row and the
 * second reads what the first wrote.
 *
 * A single process cannot hold two transactions open against itself, so the
 * lock is asserted from the statements each action issues, in order — the
 * first read of the guarded table must be the locking one. A read of the same
 * row before the lock would pass every sequential test and still let two
 * overlapping executions both see "not yet".
 *
 * The other three payments listeners are covered by their own mechanisms and
 * need no pin here, with proof rather than by omission:
 *
 *  - `RegisterDomainOnPayment`: the guard and the lock are the same statement
 *    — `where('state', Requested)->lockForUpdate()->get()` inside one
 *    transaction — on one table, so there is no order to pin.
 *  - `ResizeOnPlanChangeSettlement`: reaches `CreateProvisioningJob`, a single
 *    `insertOrIgnore` against a column carrying a unique index; two
 *    overlapping executions both insert, exactly one creates the row and
 *    dispatches, and the guard is the index. `ApplyPlanChange` takes no lock
 *    at all and relies on an idempotency key of the same kind.
 *  - `FulfilOrderOnSettlement`: takes no lock of its own because every step
 *    carries its own guard. Its `coupon_redemptions … ->exists()` is an
 *    unlocked read over a table with no unique index on `order_id`; that read
 *    is a fast path, and the serialisation point is `RedeemCoupon`, which
 *    locks the coupon row and re-reads by `order_id` inside the lock.
 */
final class AnIdempotencyGuardIsReadUnderItsOwnLockTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Each statement touching a table, in order, and whether it locked.
     *
     * @return list<array{table: string, locked: bool}>
     */
    private function statementsOn(string $table, callable $work): array
    {
        $statements = [];

        DB::listen(static function ($query) use (&$statements, $table): void {
            $sql = strtolower($query->sql);

            if (str_starts_with(ltrim($sql), 'select') && preg_match('/from\s+"'.$table.'"/', $sql) === 1) {
                $statements[] = ['table' => $table, 'locked' => str_contains($sql, 'for update')];
            }
        });

        $work();

        return $statements;
    }

    private function assertFirstReadIsLocked(string $table, callable $work, string $because): void
    {
        $statements = $this->statementsOn($table, $work);

        $this->assertNotSame([], $statements, "Nothing read {$table} at all.");
        $this->assertTrue($statements[0]['locked'], $because);
    }

    /**
     * @return array{0: Invoice, 1: Transaction}
     */
    private function invoiceWithACapture(int $minor = 9_000): array
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

    private function failureFor(Subscription $subscription, ?string $transactionId = null): PaymentFailed
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::factory()->create([
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
        ]);

        return new PaymentFailed(
            transactionId: $transactionId ?? (string) Str::ulid(),
            customerId: (string) $subscription->customer_id,
            invoiceId: (string) $invoice->id,
            provider: 'fake',
            providerReference: 'pi_'.Str::random(8),
            amount: Money::ofMinor(9_000, 'KWD'),
            failureCode: 'card_declined',
            failureMessage: 'Declined',
            failedAt: CarbonImmutable::now(),
        );
    }

    #[Test]
    public function a_redelivered_payment_failure_is_counted_once(): void
    {
        $subscription = Subscription::factory()->create();
        $failure = $this->failureFor($subscription);
        $listener = app(StartDunningOnFailedPayment::class);

        $listener->handle($failure);
        $listener->handle($failure);
        $listener->handle($failure);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(1, $subscription->failed_payment_count, 'One declined payment, delivered three times, is one failure.');
    }

    #[Test]
    public function two_different_failures_are_two(): void
    {
        $subscription = Subscription::factory()->create();
        $listener = app(StartDunningOnFailedPayment::class);

        $listener->handle($this->failureFor($subscription));
        $listener->handle($this->failureFor($subscription));

        $this->assertSame(2, $subscription->refresh()->failed_payment_count);
    }

    #[Test]
    public function a_failure_redelivered_after_the_customer_paid_does_not_reopen_dunning(): void
    {
        $subscription = Subscription::factory()->create();
        $failure = $this->failureFor($subscription);
        $listener = app(StartDunningOnFailedPayment::class);

        $listener->handle($failure);
        app(AdvanceDunning::class)->recordSuccessfulPayment($subscription);
        $listener->handle($failure);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame(0, $subscription->failed_payment_count);
    }

    #[Test]
    public function an_unkeyed_caller_is_counted_every_time(): void
    {
        $subscription = Subscription::factory()->create();
        $dunning = app(AdvanceDunning::class);

        $dunning->recordFailedPayment($subscription);
        $dunning->recordFailedPayment($subscription);

        $this->assertSame(2, $subscription->refresh()->failed_payment_count);
    }

    #[Test]
    public function a_queued_refund_whose_row_cannot_be_read_books_nothing_and_retries(): void
    {
        [$invoice, $transaction] = $this->invoiceWithACapture();

        $invoice->forceFill(['status' => InvoiceStatus::Paid, 'amount_paid_minor' => 9_000])->save();

        $event = new RefundIssued(
            refundId: (string) Str::ulid(),
            transactionId: (string) $transaction->id,
            customerId: (string) $invoice->customer_id,
            invoiceId: (string) $invoice->id,
            provider: 'fake',
            amount: Money::ofMinor(3_000, 'KWD'),
            remainingRefundable: Money::ofMinor(6_000, 'KWD'),
            reason: 'requested_by_customer',
            issuedByUserId: null,
            issuedAt: CarbonImmutable::now(),
        );

        $listener = app(RecordRefundAgainstTheInvoice::class);

        foreach ([1, 2] as $delivery) {
            try {
                $listener->handle($event);
                $this->fail("Delivery {$delivery} booked a refund it could not key.");
            } catch (RuntimeException) {
                // Thrown, so the queue retries it and, if the row never
                // appears, a person finds it in failed_jobs.
            }
        }

        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor, 'Without the refund row there is nothing to stop a second booking, so there must not be a first.');
    }

    #[Test]
    public function settlement_reads_the_invoice_it_guards_under_the_lock(): void
    {
        [$invoice, $transaction] = $this->invoiceWithACapture();

        $this->assertFirstReadIsLocked('invoices', function () use ($invoice, $transaction): void {
            app(SettleInvoice::class)->execute($invoice, $transaction);
        }, 'SettleInvoice must read the invoice FOR UPDATE before deciding what is still owed.');
    }

    #[Test]
    public function a_refund_reads_the_row_that_says_it_was_recorded_under_the_lock(): void
    {
        [$invoice, $transaction] = $this->invoiceWithACapture();

        $invoice->forceFill(['status' => InvoiceStatus::Paid, 'amount_paid_minor' => 9_000])->save();

        $refund = \Lynomia\Modules\Payments\Infrastructure\Models\Refund::factory()->create([
            'transaction_id' => $transaction->id,
            'amount_minor' => 3_000,
            'currency' => 'KWD',
        ]);

        $this->assertFirstReadIsLocked('refunds', function () use ($invoice, $refund): void {
            app(RecordInvoiceRefund::class)->execute($invoice, Money::ofMinor(3_000, 'KWD'), $refund);
        }, 'recorded_on_invoice_at is the guard; it must be read from a locked row.');
    }

    #[Test]
    public function dunning_reads_the_failure_it_last_counted_under_the_lock(): void
    {
        $subscription = Subscription::factory()->create();

        $this->assertFirstReadIsLocked('subscriptions', function () use ($subscription): void {
            app(AdvanceDunning::class)->recordFailedPayment($subscription, null, (string) Str::ulid());
        }, 'last_counted_payment_failure_id is the guard; it must be read from a locked row.');
    }

    #[Test]
    public function renewal_reads_the_subscription_it_revives_under_the_lock(): void
    {
        $subscription = Subscription::factory()->create();
        app(AdvanceDunning::class)->recordFailedPayment($subscription);

        $this->assertFirstReadIsLocked('subscriptions', function () use ($subscription): void {
            app(AdvanceDunning::class)->recordSuccessfulPayment($subscription);
        }, 'recordSuccessfulPayment must read the subscription FOR UPDATE before resetting its clocks.');
    }
}
