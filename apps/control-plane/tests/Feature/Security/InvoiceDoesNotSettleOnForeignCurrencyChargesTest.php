<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Billing\Application\Actions\SettleInvoice;
use Lynomia\Modules\Billing\Domain\Enums\InvoiceStatus;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Billing\Infrastructure\Models\Invoice;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Application\Actions\RecordPaymentCapture;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression: an invoice must not settle on the raw minor units of charges in
 * another currency, or on charges belonging to another customer.
 *
 * SettleInvoice guards the capture it is handed — assertSettleable() refuses a
 * currency mismatch and a capture belonging to another customer. It then adds
 * that capture to a SUM over every other charge attached to the invoice:
 *
 *     $otherChargesMinor = Transaction::query()
 *         ->where('invoice_id', $locked->getKey())
 *         ->whereKeyNot($capture->getKey())
 *         ->where('kind', 'charge')
 *         ->where('status', 'succeeded')
 *         ->sum('amount_minor');
 *
 * That query used to filter on neither currency nor customer, so 30000 US
 * cents and 30000 Kuwaiti fils were added together as the integer 30000 —
 * exactly the conversion the platform's first invariant says never happens,
 * and one that turns a cheap currency into a discount on an expensive one.
 * Nothing stops such a row existing: RecordPaymentCapture writes invoice_id
 * straight from the payment's metadata without comparing the payment's
 * currency, or its customer, against the invoice named there.
 *
 * The sum now carries the same currency and customer conditions
 * assertSettleable() applies to the capture in hand, so a foreign row attached
 * to the document is worth nothing to it.
 */
final class InvoiceDoesNotSettleOnForeignCurrencyChargesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function thirty_thousand_us_cents_do_not_pay_a_thirty_dinar_invoice(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()
            ->open()
            ->totalling(Money::ofMinor(30000, 'KWD'))   // 30.000 KWD, ~USD 98
            ->create(['customer_id' => $customer->id]);

        // A succeeded USD charge attached to this KWD invoice. This is the row
        // shape RecordPaymentCapture produces for a capture whose metadata
        // names this invoice: it copies invoice_id across without ever
        // comparing currencies.
        Transaction::query()->create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'kind' => TransactionKind::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => 30000,                     // USD 300.00
            'currency' => 'USD',
            'provider_reference' => 'pi_usd_foreign',
            'processed_at' => now(),
        ]);

        // Now one fils, in the right currency, so assertSettleable is happy
        // with the capture it is actually handed.
        /** @var Transaction $token */
        $token = Transaction::query()->create([
            'customer_id' => $customer->id,
            'provider' => 'fake',
            'kind' => TransactionKind::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => 1,                          // 0.001 KWD
            'currency' => 'KWD',
            'provider_reference' => 'pi_kwd_token',
            'processed_at' => now(),
        ]);

        $settled = app(SettleInvoice::class)->execute($invoice, $token)->invoice;

        $this->assertSame(
            1,
            $settled->amount_paid_minor,
            'Only the one fils actually paid in the invoice currency may count towards it.',
        );
        $this->assertSame(29999, $settled->amount_due_minor);
        $this->assertSame(
            InvoiceStatus::Open,
            $settled->status,
            'A 30.000 KWD invoice was marked paid by 0.001 KWD plus a USD charge counted as fils.',
        );
    }

    #[Test]
    public function a_charge_belonging_to_another_customer_does_not_pay_the_invoice(): void
    {
        $customer = Customer::factory()->create(['currency' => 'KWD']);
        $stranger = Customer::factory()->create(['currency' => 'KWD']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()
            ->open()
            ->totalling(Money::ofMinor(30000, 'KWD'))
            ->create(['customer_id' => $customer->id]);

        Transaction::query()->create([
            'customer_id' => $stranger->id,
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'kind' => TransactionKind::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => 30000,
            'currency' => 'KWD',
            'provider_reference' => 'pi_kwd_stranger',
            'processed_at' => now(),
        ]);

        /** @var Transaction $token */
        $token = Transaction::query()->create([
            'customer_id' => $customer->id,
            'provider' => 'fake',
            'kind' => TransactionKind::Charge,
            'status' => TransactionStatus::Succeeded,
            'amount_minor' => 1,
            'currency' => 'KWD',
            'provider_reference' => 'pi_kwd_token_2',
            'processed_at' => now(),
        ]);

        $settled = app(SettleInvoice::class)->execute($invoice, $token)->invoice;

        $this->assertSame(1, $settled->amount_paid_minor);
        $this->assertSame(InvoiceStatus::Open, $settled->status);
    }

    /**
     * Where such a row comes from: the ordinary capture path.
     *
     * RecordPaymentCapture copies the metadata's invoice_id onto the
     * transaction without ever asking whether that invoice is denominated in
     * the currency the money arrived in, or even owned by the payer. In
     * production the settlement listener is queued, so this row commits and is
     * still attached long after the settlement job has failed on the mismatch.
     */
    #[Test]
    public function recording_a_capture_attaches_it_to_an_invoice_in_another_currency(): void
    {
        Event::fake([PaymentCaptured::class]);

        $customer = Customer::factory()->create(['currency' => 'KWD']);

        /** @var Invoice $invoice */
        $invoice = Invoice::factory()
            ->open()
            ->totalling(Money::ofMinor(30000, 'KWD'))
            ->create(['customer_id' => $customer->id]);

        $capture = app(RecordPaymentCapture::class)->execute('fake', new ProviderEvent(
            providerEventId: 'evt_usd_capture',
            type: 'payment.succeeded',
            kind: ProviderEventKind::PaymentSucceeded,
            amount: Money::ofMinor(30000, 'USD'),
            currency: 'USD',
            providerReference: 'pi_usd_capture',
            payload: [],
            metadata: ['customer_id' => (string) $customer->id, 'invoice_id' => (string) $invoice->id],
        ));

        $this->assertSame('USD', $capture->currency);
        $this->assertSame(
            $invoice->id,
            $capture->invoice_id,
            'A USD capture is attached to a KWD invoice, where the settlement SUM will count it as fils.',
        );
    }
}
