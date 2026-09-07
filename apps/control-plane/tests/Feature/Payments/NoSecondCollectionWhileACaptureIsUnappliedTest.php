<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Payments\Application\Actions\StartInvoicePayment;
use Lynomia\Modules\Payments\Domain\Enums\PaymentAttemptStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\InvoicePaymentRefusedException;
use Lynomia\Modules\Payments\Infrastructure\Models\PaymentAttempt;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Starting a payment while money is already in.
 *
 * The guard that stops a second collection inspected only the newest pending
 * attempt. That is the right attempt to *reuse*, and not a complete answer to
 * "has this already been paid?": an attempt abandoned because the amount moved
 * while it was open is no longer pending, and its capture can still land
 * afterwards. Until settlement runs, the invoice is open with a positive
 * amount due and nothing in the newest attempt says otherwise — so the customer
 * is invited to pay again.
 */
final class NoSecondCollectionWhileACaptureIsUnappliedTest extends TestCase
{
    use RefreshDatabase;

    private function openInvoice(): Invoice
    {
        return Invoice::factory()
            ->totalling(Money::of('10.000', 'KWD'))
            ->create(['status' => InvoiceStatus::Open]);
    }

    #[Test]
    public function an_unapplied_capture_on_an_abandoned_attempt_stops_a_second_payment(): void
    {
        $invoice = $this->openInvoice();

        // The attempt was abandoned when the amount moved, and the capture for
        // it arrived afterwards. Settlement has not run: invoice_id on the
        // transaction is still null, which is settlement's own marker for
        // "not applied".
        $capture = Transaction::factory()->create([
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => 10000,
            'currency' => 'KWD',
            'invoice_id' => null,
        ]);

        PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'transaction_id' => $capture->id,
            'attempt_number' => 1,
            'status' => PaymentAttemptStatus::Abandoned,
        ]);

        try {
            app(StartInvoicePayment::class)->execute($invoice);
            $this->fail('A second collection was started while a capture was waiting to be applied.');
        } catch (InvoicePaymentRefusedException $e) {
            $this->assertSame('payment.already_captured', $e->errorCode());
        }

        // No new attempt was opened, so nothing was sent to the provider.
        $this->assertSame(1, PaymentAttempt::query()->count());
    }

    #[Test]
    public function an_applied_capture_does_not_block_the_remaining_balance(): void
    {
        $invoice = $this->openInvoice();

        // Attached to the invoice, so settlement has applied it. A partial
        // payment must still be able to collect the rest — a guard that
        // refused here would make every part-paid invoice unpayable.
        $capture = Transaction::factory()->create([
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => 4000,
            'currency' => 'KWD',
            'invoice_id' => $invoice->id,
        ]);

        PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id,
            'transaction_id' => $capture->id,
            'attempt_number' => 1,
            'status' => PaymentAttemptStatus::Succeeded,
        ]);

        $invoice->forceFill(['amount_paid_minor' => 4000])->save();

        $started = app(StartInvoicePayment::class)->execute($invoice->refresh());

        $this->assertSame(6000, $started->transaction->amount_minor);
    }

    #[Test]
    public function a_capture_belonging_to_another_invoice_is_not_this_invoices_business(): void
    {
        $mine = $this->openInvoice();
        $theirs = $this->openInvoice();

        $capture = Transaction::factory()->create([
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => 10000,
            'currency' => 'KWD',
            'invoice_id' => null,
        ]);

        PaymentAttempt::query()->create([
            'invoice_id' => $theirs->id,
            'transaction_id' => $capture->id,
            'attempt_number' => 1,
            'status' => PaymentAttemptStatus::Abandoned,
        ]);

        // Somebody else's unapplied capture must not make this invoice
        // unpayable.
        $started = app(StartInvoicePayment::class)->execute($mine);

        $this->assertSame(10000, $started->transaction->amount_minor);
    }
}
