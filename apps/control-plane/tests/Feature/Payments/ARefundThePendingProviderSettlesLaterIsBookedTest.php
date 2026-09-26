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
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Infrastructure\Models\Refund;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
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
 * 05 and sends `refund.updated` through emitRefundUpdate(), signed like any
 * other webhook.
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

        $update = (new FakePaymentProvider)->emitRefundUpdate((string) $refund->provider_reference, RefundStatus::Succeeded, Money::ofMinor(5_005, 'KWD'));

        app(IngestWebhookEvent::class)->execute('fake', $update->rawPayload, $update->headers);

        $this->assertSame(RefundStatus::Succeeded, $refund->refresh()->status);
        $this->assertNotNull($refund->processed_at);
        $this->assertSame(5_005, $invoice->refresh()->amount_refunded_minor, 'The settled refund is booked on the invoice.');
        $this->assertSame(InvoiceStatus::Refunded, $invoice->status);

        // A redelivery, or a second copy of the event, books nothing more.
        app(IngestWebhookEvent::class)->execute('fake', $update->rawPayload, $update->headers);
        $again = (new FakePaymentProvider)->emitRefundUpdate((string) $refund->provider_reference, RefundStatus::Succeeded, Money::ofMinor(5_005, 'KWD'));
        app(IngestWebhookEvent::class)->execute('fake', $again->rawPayload, $again->headers);

        $this->assertSame(5_005, $invoice->refresh()->amount_refunded_minor);
    }

    #[Test]
    public function a_pending_refund_that_fails_releases_what_it_reserved_and_books_nothing(): void
    {
        [$invoice, $capture] = $this->paidInvoice(5_005);

        $refund = app(IssueRefund::class)->execute($capture, Money::ofMinor(5_005, 'KWD'), 'customer asked');
        $this->assertTrue($capture->refresh()->refundableAmount()->isZero(), 'Precondition: the pending refund reserves the capture.');

        $update = (new FakePaymentProvider)->emitRefundUpdate((string) $refund->provider_reference, RefundStatus::Failed, Money::ofMinor(5_005, 'KWD'));
        app(IngestWebhookEvent::class)->execute('fake', $update->rawPayload, $update->headers);

        $this->assertSame(RefundStatus::Failed, $refund->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor);
        $this->assertSame(5_005, $capture->refresh()->refundableAmount()->minorUnits(), 'No money moved, so the capture is refundable again.');

        // And a late "succeeded" for a refund already closed changes nothing.
        $late = (new FakePaymentProvider)->emitRefundUpdate((string) $refund->provider_reference, RefundStatus::Succeeded, Money::ofMinor(5_005, 'KWD'));
        app(IngestWebhookEvent::class)->execute('fake', $late->rawPayload, $late->headers);

        $this->assertSame(RefundStatus::Failed, $refund->refresh()->status);
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor);
    }

    #[Test]
    public function an_update_for_a_refund_this_platform_never_made_changes_nothing(): void
    {
        [$invoice] = $this->paidInvoice(5_005);

        $update = (new FakePaymentProvider)->emitRefundUpdate('fake_re_nobody_knows', RefundStatus::Succeeded, Money::ofMinor(5_005, 'KWD'));
        app(IngestWebhookEvent::class)->execute('fake', $update->rawPayload, $update->headers);

        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, $invoice->refresh()->amount_refunded_minor);
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
