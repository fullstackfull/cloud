<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Application\Actions\IngestWebhookEvent;
use Lynomia\Modules\Payments\Application\Actions\IssueRefund;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\RefundNotYetRecordedException;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Models\WebhookEvent;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Wallet\Infrastructure\Models\WalletTransaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A refund the provider answered `pending` is settled by the provider's later
 * event, and booked on the invoice.
 *
 * Stripe answers some refunds `pending` (and maps any status the adapter does
 * not know to it). IssueRefund recorded that and announced nothing; the
 * adapter recognised `refund.updated` and IngestWebhookEvent acknowledged it
 * and did nothing else; so the row stayed pending for ever - the money
 * reserved against the capture, never booked on the invoice, and (the
 * skeptic's finding) invisible to everything that reads the invoice's own
 * refunded figure.
 *
 * The controlled provider answers `pending` for a refund whose amount ends in
 * 05 and sends the refund's event through emitWebhook() with the refund's
 * status, signed like any other webhook.
 */
final class ARefundThePendingProviderSettlesLaterIsBookedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_pending_refund_is_settled_and_booked_when_the_provider_says_it_succeeded(): void
    {
        [$invoice, $capture] = $this->paidInvoice(5_005);

        $refund = app(IssueRefund::class)->execute($capture, Money::ofMinor(5_005, 'KWD'), 'customer asked');

        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor, 'Precondition: nothing is booked while it is pending.');

        $update = (new FakePaymentProvider)->emitWebhook(ProviderEventKind::RefundSucceeded, (string) $refund->provider_reference, Money::ofMinor(5_005, 'KWD'), refundStatus: RefundStatus::Succeeded);

        app(IngestWebhookEvent::class)->execute('fake', $update->rawPayload, $update->headers);

        $this->assertSame(RefundStatus::Succeeded, $refund->refresh()->status);
        $this->assertNotNull($refund->processed_at);
        $this->assertSame(5_005, $invoice->refresh()->amount_refunded_minor, 'The settled refund is booked on the invoice.');
        $this->assertSame(InvoiceStatus::Refunded, $invoice->status);

        // A redelivery, or a second copy of the event, books nothing more.
        app(IngestWebhookEvent::class)->execute('fake', $update->rawPayload, $update->headers);
        $again = (new FakePaymentProvider)->emitWebhook(ProviderEventKind::RefundSucceeded, (string) $refund->provider_reference, Money::ofMinor(5_005, 'KWD'), refundStatus: RefundStatus::Succeeded);
        app(IngestWebhookEvent::class)->execute('fake', $again->rawPayload, $again->headers);

        $this->assertSame(5_005, $invoice->refresh()->amount_refunded_minor);
    }

    #[Test]
    public function a_pending_refund_that_fails_releases_what_it_reserved_and_books_nothing(): void
    {
        [$invoice, $capture] = $this->paidInvoice(5_005);

        $refund = app(IssueRefund::class)->execute($capture, Money::ofMinor(5_005, 'KWD'), 'customer asked');
        $this->assertTrue($capture->refresh()->refundableAmount()->isZero(), 'Precondition: the pending refund reserves the capture.');

        $update = (new FakePaymentProvider)->emitWebhook(ProviderEventKind::RefundSucceeded, (string) $refund->provider_reference, Money::ofMinor(5_005, 'KWD'), refundStatus: RefundStatus::Failed);
        app(IngestWebhookEvent::class)->execute('fake', $update->rawPayload, $update->headers);

        $this->assertSame(RefundStatus::Failed, $refund->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor);
        $this->assertSame(5_005, $capture->refresh()->refundableAmount()->minorUnits(), 'No money moved, so the capture is refundable again.');
        $this->assertSame(0, (int) WalletTransaction::query()->where('invoice_id', $invoice->getKey())->sum('amount_minor'), 'The invoice still stands, so nothing of it goes to the wallet.');

        // And a late "succeeded" for a refund already closed changes nothing.
        $late = (new FakePaymentProvider)->emitWebhook(ProviderEventKind::RefundSucceeded, (string) $refund->provider_reference, Money::ofMinor(5_005, 'KWD'), refundStatus: RefundStatus::Succeeded);
        app(IngestWebhookEvent::class)->execute('fake', $late->rawPayload, $late->headers);

        $this->assertSame(RefundStatus::Failed, $refund->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor);
    }

    #[Test]
    public function a_refund_event_quicker_than_the_refunds_own_answer_is_answered_for_a_redelivery_not_lost(): void
    {
        /*
         * R1. IssueRefund stores the provider's reference for the refund only
         * once the provider has answered; an event arriving before that was
         * acknowledged (200, processed) and lost, and the row stayed pending.
         */
        [$invoice, $capture] = $this->paidInvoice(5_005);
        $refund = app(IssueRefund::class)->execute($capture, Money::ofMinor(5_005, 'KWD'), 'customer asked');
        $reference = (string) $refund->provider_reference;

        // The row as it stood before the answer was written.
        $refund->forceFill(['provider_reference' => null])->save();

        $early = (new FakePaymentProvider)->emitWebhook(ProviderEventKind::RefundSucceeded, $reference, Money::ofMinor(5_005, 'KWD'), refundStatus: RefundStatus::Succeeded);

        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($early->headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->call('POST', route('webhooks.receive', ['provider' => 'fake']), server: $server, content: $early->rawPayload)
            ->assertStatus(503);

        /** @var WebhookEvent $record */
        $record = WebhookEvent::query()->sole();
        $this->assertFalse($record->status->isSettled(), 'The event was marked handled, so no redelivery would ever settle the refund.');
        $this->assertSame(RefundStatus::Pending, $refund->fresh()?->status);

        // The answer is written; the provider redelivers the same event.
        $refund->forceFill(['provider_reference' => $reference])->save();
        app(IngestWebhookEvent::class)->execute('fake', $early->rawPayload, $early->headers);

        $this->assertSame(RefundStatus::Succeeded, $refund->fresh()?->status);
        $this->assertSame(5_005, $invoice->refresh()->amount_refunded_minor);
        $this->assertTrue($record->fresh()?->status->isSettled());
    }

    #[Test]
    public function an_update_for_a_refund_no_row_carries_changes_nothing_and_is_not_settled(): void
    {
        [$invoice] = $this->paidInvoice(5_005);

        $update = (new FakePaymentProvider)->emitWebhook(ProviderEventKind::RefundSucceeded, 'fake_re_nobody_knows', Money::ofMinor(5_005, 'KWD'), refundStatus: RefundStatus::Succeeded);

        try {
            app(IngestWebhookEvent::class)->execute('fake', $update->rawPayload, $update->headers);
            $this->fail('An unknown refund was acknowledged.');
        } catch (RefundNotYetRecordedException $e) {
            $this->assertSame(503, $e->httpStatus());
        }

        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor);
        $this->assertFalse(WebhookEvent::query()->sole()->status->isSettled());
    }

    /**
     * @return array{Invoice, Transaction}
     */
    private function paidInvoice(int $minor): array
    {
        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);

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

        $capture = Transaction::factory()->amount(Money::ofMinor($minor, 'KWD'))->create(['customer_id' => $customer->getKey()]);
        app(SettleInvoice::class)->execute($invoice, $capture);

        return [$invoice->refresh(), $capture->refresh()];
    }
}
