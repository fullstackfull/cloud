<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Application\Actions\VoidInvoice;
use Lynomia\Modules\Billing\Application\Listeners\RecordRefundAgainstTheInvoice;
use Lynomia\Modules\Billing\Application\Queries\WhatAnInvoiceStillHolds;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Application\Actions\IngestWebhookEvent;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Application\Actions\SettleRefundFromProvider;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Events\RefundIssued;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Payments\Infrastructure\Providers\StripePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Lynomia\Modules\Wallet\Domain\Enums\WalletTransactionKind;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * A refund the provider reports failed (or cancelled) after it had reported it
 * succeeded is reversed, not dropped.
 *
 * OA-4, round four's re-audit: the Stripe adapter maps a `refund.updated` /
 * `charge.refund.updated` whose refund reads `failed` or `canceled` to
 * RefundStatus::Failed / Cancelled, whatever the refund's earlier status.
 * SettleRefundFromProvider moved only a pending row, so for a refund already
 * booked as succeeded the report was dropped silently: the platform went on
 * saying the money was returned while the provider had put it back on the
 * merchant's balance. Whether Stripe reports that for a refund it had
 * reported succeeded is not confirmed against the real provider (no real
 * provider is verified); the adapter maps it, so the platform acts on it.
 *
 * The reversal: the row is marked as the provider says; it is un-booked from
 * the invoice (amount_refunded) and so from the capture (whose refunded
 * figure is read from its rows), so WhatAnInvoiceStillHolds holds it again;
 * when the invoice is void or already refunded (terminal - nothing more is
 * delivered, nothing reopens it) what it holds goes to the wallet, otherwise
 * it is left refundable. An audit entry and a log line surface it.
 */
final class ARefundTheProviderReversesAfterItSucceededIsUnbookedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_part_refund_reversed_by_the_provider_is_unbooked_and_left_refundable(): void
    {
        [$invoice, $capture] = $this->paidInvoice(10_000);

        $refund = app(IssueRefund::class)->execute($capture, Money::ofMinor(3_000, 'KWD'), 'customer asked');
        $this->assertSame(RefundStatus::Succeeded, $refund->status);
        $this->assertSame(3_000, $invoice->refresh()->amount_refunded_minor, 'Precondition: booked.');

        $this->reverse($refund, RefundStatus::Failed);

        $this->assertSame(RefundStatus::Failed, $refund->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor, 'The reversed refund is no longer booked on the invoice.');
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(10_000, $capture->refresh()->refundableAmount()->minorUnits(), 'The capture is refundable again.');
        $this->assertSame(10_000, WhatAnInvoiceStillHolds::minor($invoice), 'The invoice holds the money again.');
        $this->assertSame(0, (int) WalletTransaction::query()->where('invoice_id', $invoice->getKey())->sum('amount_minor'), 'A standing invoice sends nothing to the wallet.');

        $entry = AuditEntry::query()->where('action', AuditAction::RefundReversedByProvider->value)->sole();
        $this->assertSame((string) $refund->getKey(), (string) $entry->subject_id);

        // A redelivery of the same report reverses nothing twice.
        $this->reverse($refund, RefundStatus::Failed);
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor);
        $this->assertSame(1, AuditEntry::query()->where('action', AuditAction::RefundReversedByProvider->value)->count());
    }

    #[Test]
    public function a_whole_refund_reversed_by_the_provider_goes_to_the_wallet_once(): void
    {
        [$invoice, $capture] = $this->paidInvoice(10_000);

        $refund = app(IssueRefund::class)->execute($capture, Money::ofMinor(10_000, 'KWD'), 'customer asked');
        $this->assertSame(InvoiceStatus::Refunded, $invoice->refresh()->status, 'Precondition: fully refunded.');

        $this->reverse($refund, RefundStatus::Cancelled);
        $this->reverse($refund, RefundStatus::Cancelled);

        $this->assertSame(RefundStatus::Cancelled, $refund->refresh()->status);
        $this->assertSame(InvoiceStatus::Refunded, $invoice->refresh()->status, 'Refunded is terminal; the money goes back by the wallet instead.');
        $credits = WalletTransaction::query()->where('invoice_id', $invoice->getKey())->where('kind', WalletTransactionKind::Topup->value)->pluck('amount_minor')->all();
        $this->assertSame([10_000], $credits, 'What the provider put back went to the wallet, recorded against the invoice, once.');
        $this->assertSame(0, WhatAnInvoiceStillHolds::minor($invoice));
    }

    #[Test]
    public function a_refund_reversed_on_a_void_invoice_goes_to_the_wallet(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $invoice = $this->openInvoice($customer, 9_000);
        $capture = Transaction::factory()->amount(Money::ofMinor(5_000, 'KWD'))->create(['customer_id' => $customer->getKey()]);
        app(SettleInvoice::class)->execute($invoice, $capture);

        $refund = app(IssueRefund::class)->execute($capture->refresh(), Money::ofMinor(5_000, 'KWD'), 'customer asked');
        $this->assertSame(5_000, $invoice->refresh()->amount_refunded_minor);
        app(VoidInvoice::class)->execute($invoice->refresh(), 'withdrawn');
        $this->assertSame(InvoiceStatus::Void, $invoice->refresh()->status);

        $this->reverse($refund, RefundStatus::Failed);

        $this->assertSame(5_000, (int) WalletTransaction::query()->where('invoice_id', $invoice->getKey())->where('kind', WalletTransactionKind::Topup->value)->sum('amount_minor'));
        $this->assertSame(0, WhatAnInvoiceStillHolds::minor($invoice->refresh()));
    }

    #[Test]
    public function the_stripe_shape_of_the_report_reverses_it(): void
    {
        /*
         * The adapter's own shape: `charge.refund.updated` carrying the
         * refund, status `failed`, parsed by StripePaymentProvider (no call
         * is made) and settled for the `stripe` capture it names.
         */
        [$invoice, $capture] = $this->paidInvoice(10_000, provider: 'stripe');

        $refund = Refund::query()->create([
            'transaction_id' => $capture->getKey(),
            'provider_reference' => 're_test_reversed',
            'amount_minor' => 2_500,
            'currency' => 'KWD',
            'status' => RefundStatus::Succeeded,
            'reason' => 'customer asked',
            'processed_at' => now(),
        ]);
        app(RecordRefundAgainstTheInvoice::class)->handle($this->issued($refund, $capture, $invoice));
        $this->assertSame(2_500, $invoice->refresh()->amount_refunded_minor);

        $stripe = new StripePaymentProvider(
            new StripeClient(['api_key' => 'sk_test_0000000000000000000000']),
            new SecretRedactor(['secret', 'token', 'api_key', 'card']),
        );
        $event = $stripe->parseWebhookEvent([
            'id' => 'evt_refund_reversed',
            'type' => 'charge.refund.updated',
            'created' => time(),
            'data' => ['object' => [
                'id' => 're_test_reversed',
                'object' => 'refund',
                'payment_intent' => (string) $capture->provider_reference,
                'amount' => 2500,
                'currency' => 'kwd',
                'status' => 'failed',
            ]],
        ]);
        $this->assertNotNull($event);
        $this->assertSame(RefundStatus::Failed, $event->refundStatus);

        app(SettleRefundFromProvider::class)->execute('stripe', $event);

        $this->assertSame(RefundStatus::Failed, $refund->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor);
        $this->assertSame(10_000, WhatAnInvoiceStillHolds::minor($invoice));
    }

    #[Test]
    public function a_refund_reversed_before_its_booking_was_heard_is_never_booked(): void
    {
        [$invoice, $capture] = $this->paidInvoice(10_000);

        Event::fake([RefundIssued::class]);
        $refund = app(IssueRefund::class)->execute($capture, Money::ofMinor(3_000, 'KWD'), 'customer asked');
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor, 'Precondition: the booking is not heard yet.');

        $this->reverse($refund, RefundStatus::Failed);

        // The booking, heard late, finds the refund reversed and books nothing.
        app(RecordRefundAgainstTheInvoice::class)->handle($this->issued($refund->refresh(), $capture, $invoice));

        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor);
        $this->assertSame(10_000, WhatAnInvoiceStillHolds::minor($invoice));
    }

    private function reverse(Refund $refund, RefundStatus $status): void
    {
        $report = (new FakePaymentProvider)->emitWebhook(
            ProviderEventKind::RefundSucceeded,
            (string) $refund->provider_reference,
            Money::ofMinor($refund->amount_minor, 'KWD'),
            refundStatus: $status,
        );

        app(IngestWebhookEvent::class)->execute('fake', $report->rawPayload, $report->headers);
    }

    private function issued(Refund $refund, Transaction $capture, Invoice $invoice): RefundIssued
    {
        return new RefundIssued(
            refundId: (string) $refund->getKey(),
            transactionId: (string) $capture->getKey(),
            customerId: (string) $capture->customer_id,
            invoiceId: (string) $invoice->getKey(),
            provider: (string) $capture->provider,
            amount: Money::ofMinor($refund->amount_minor, 'KWD'),
            remainingRefundable: $capture->refresh()->refundableAmount(),
            reason: 'customer asked',
            issuedByUserId: null,
            issuedAt: now()->toImmutable(),
        );
    }

    private function openInvoice(Customer $customer, int $minor): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->getKey(),
            'currency' => 'KWD',
            'status' => InvoiceStatus::Open,
            'subtotal_minor' => $minor,
            'total_minor' => $minor,
            'amount_paid_minor' => 0,
            'amount_refunded_minor' => 0,
        ]);

        return $invoice;
    }

    /**
     * @return array{Invoice, Transaction}
     */
    private function paidInvoice(int $minor, string $provider = 'fake'): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $invoice = $this->openInvoice($customer, $minor);

        $capture = Transaction::factory()->amount(Money::ofMinor($minor, 'KWD'))->create(array_filter([
            'customer_id' => $customer->getKey(),
            'provider' => $provider === 'fake' ? null : $provider,
            'provider_reference' => $provider === 'fake' ? null : 'pi_test_reversed',
        ]));
        app(SettleInvoice::class)->execute($invoice, $capture);

        return [$invoice->refresh(), $capture->refresh()];
    }
}
