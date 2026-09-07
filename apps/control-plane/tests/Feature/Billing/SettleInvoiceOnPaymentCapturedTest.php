<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Lynomia\Modules\Billing\Application\Listeners\SettleInvoiceOnPaymentCaptured;
use Lynomia\Modules\Billing\Domain\Exceptions\UnsettleableCaptureException;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The listener must never abandon a capture it cannot read.
 *
 * It used to log a warning and return when either row came back null, on the
 * reasoning that a retry cannot make a deleted row reappear. But the row is
 * far more likely to be one the job simply cannot see yet, and by the time the
 * job runs the provider has been answered 200 and the webhook_events row says
 * "processed" — so nothing ever comes back to it. Money captured, invoice
 * never settled, no service, no retry, one log line.
 */
final class SettleInvoiceOnPaymentCapturedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_throws_when_the_capture_it_names_cannot_be_read(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()
            ->open()
            ->totalling(Money::ofMinor(10000, 'KWD'))
            ->create(['customer_id' => $customer->id]);

        $this->expectException(UnsettleableCaptureException::class);

        app(SettleInvoiceOnPaymentCaptured::class)->handle(new PaymentCaptured(
            transactionId: '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            customerId: (string) $customer->id,
            invoiceId: (string) $invoice->id,
            provider: 'fake',
            providerReference: 'pi_vanished',
            amount: Money::ofMinor(10000, 'KWD'),
            capturedAt: now()->toImmutable(),
        ));
    }

    #[Test]
    public function a_capture_with_no_invoice_is_still_not_an_error(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD']);

        app(SettleInvoiceOnPaymentCaptured::class)->handle(new PaymentCaptured(
            transactionId: '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            customerId: (string) $customer->id,
            invoiceId: null,
            provider: 'fake',
            providerReference: 'pi_wallet_topup',
            amount: Money::ofMinor(10000, 'KWD'),
            capturedAt: now()->toImmutable(),
        ));

        $this->addToAssertionCount(1);
    }
}
