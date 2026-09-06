<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Application\Actions\ConfirmPaymentFromReturn;
use Lynomia\Modules\Payments\Application\Actions\RecordPaymentCapture;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Domain\Exceptions\PaymentAttributionMismatchException;
use Lynomia\Modules\Payments\Domain\Exceptions\UnattributablePaymentException;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RecordPaymentCaptureTest extends TestCase
{
    use RefreshDatabase;

    private RecordPaymentCapture $record;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->record = app(RecordPaymentCapture::class);
        $this->customer = Customer::factory()->create();
    }

    #[Test]
    public function the_same_event_recorded_twice_produces_one_transaction(): void
    {
        $event = $this->captureEvent();

        $first = $this->record->execute('fake', $event);
        $second = $this->record->execute('fake', $event);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Transaction::query()->count());
    }

    #[Test]
    public function a_second_recording_does_not_emit_a_second_capture_event(): void
    {
        $event = $this->captureEvent();
        $this->record->execute('fake', $event);

        Event::fake([PaymentCaptured::class]);
        $this->record->execute('fake', $event);

        Event::assertNotDispatched(PaymentCaptured::class);
    }

    #[Test]
    public function a_pending_transaction_for_the_same_reference_is_settled_rather_than_duplicated(): void
    {
        // The 3-D Secure shape: an intent recorded while it awaited the
        // customer, then completed. One payment, one row.
        $pending = Transaction::factory()->forCustomer($this->customer)->pending()->create([
            'provider' => 'fake',
            'provider_reference' => 'fake_pi_succeeded_KWD_9000_abcdef12',
        ]);

        $settled = $this->record->execute('fake', $this->captureEvent());

        $this->assertSame($pending->id, $settled->id);
        $this->assertSame(TransactionStatus::Succeeded, $settled->status);
        $this->assertSame(1, Transaction::query()->count());
    }

    #[Test]
    public function recording_a_capture_does_not_settle_the_invoice(): void
    {
        $invoiceId = $this->anOpenInvoice();

        $this->record->execute('fake', $this->captureEvent(), invoiceId: $invoiceId);

        // Invoice settlement is a billing decision — partial payments,
        // overpayments and credit notes all live there — so the ledger records
        // the money and stops.
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        $this->assertSame('open', $invoice?->status);
        $this->assertSame(0, (int) $invoice?->amount_paid_minor);
    }

    #[Test]
    public function a_capture_that_names_no_customer_is_refused_rather_than_guessed(): void
    {
        $event = new ProviderEvent(
            providerEventId: 'evt_orphan',
            type: 'payment.succeeded',
            kind: ProviderEventKind::PaymentSucceeded,
            amount: Money::ofMinor(9000, 'KWD'),
            currency: 'KWD',
            providerReference: 'fake_pi_succeeded_KWD_9000_orphan01',
            payload: [],
        );

        $this->expectException(UnattributablePaymentException::class);

        try {
            $this->record->execute('fake', $event);
        } finally {
            $this->assertSame(0, Transaction::query()->count());
        }
    }

    #[Test]
    public function a_browser_redirect_cannot_settle_a_payment_the_provider_has_not_taken(): void
    {
        $provider = new FakePaymentProvider;

        // The customer's browser comes back from an intent that was never
        // confirmed. Whatever the URL claims, the provider says otherwise.
        $unconfirmed = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: Money::ofMinor(9000, 'KWD'),
            idempotencyKey: 'idem_unconfirmed',
            metadata: ['customer_id' => $this->customer->id],
        ));

        $transaction = app(ConfirmPaymentFromReturn::class)
            ->execute('fake', $unconfirmed->reference, $this->customer->id);

        $this->assertNull($transaction);
        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_server_side_retrieve_settles_a_payment_the_provider_confirms(): void
    {
        $provider = new FakePaymentProvider;
        $confirmed = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: Money::ofMinor(9000, 'KWD'),
            idempotencyKey: 'idem_confirmed',
            metadata: ['customer_id' => $this->customer->id],
            confirm: true,
        ));

        $transaction = app(ConfirmPaymentFromReturn::class)
            ->execute('fake', $confirmed->reference, $this->customer->id);

        $this->assertNotNull($transaction);
        $this->assertSame(TransactionStatus::Succeeded, $transaction->status);
        $this->assertSame($this->customer->id, $transaction->customer_id);
        $this->assertTrue($transaction->amount()->equals(Money::ofMinor(9000, 'KWD')));
    }

    #[Test]
    public function a_declined_payment_confirmed_server_side_records_its_failure_code(): void
    {
        $provider = new FakePaymentProvider;
        $declined = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: FakePaymentProvider::declineAmount(Money::ofMinor(9000, 'KWD'), 'expired_card'),
            idempotencyKey: 'idem_declined',
            metadata: ['customer_id' => $this->customer->id],
            confirm: true,
        ));

        $transaction = app(ConfirmPaymentFromReturn::class)
            ->execute('fake', $declined->reference, $this->customer->id);

        $this->assertNotNull($transaction);
        $this->assertSame(TransactionStatus::Failed, $transaction->status);
        $this->assertSame('expired_card', $transaction->failure_code);
        $this->assertSame(0, Transaction::query()->where('status', TransactionStatus::Succeeded->value)->count());
    }

    #[Test]
    public function a_return_cannot_settle_someone_elses_payment_against_the_signed_in_account(): void
    {
        $provider = new FakePaymentProvider;
        $payer = Customer::factory()->create();

        // A real, genuinely succeeded payment belonging to another customer.
        // Its reference is not a secret: it travels through browser history,
        // a shared success URL and every support ticket about the order.
        $confirmed = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: Money::ofMinor(9000, 'KWD'),
            idempotencyKey: 'idem_someone_else',
            metadata: ['customer_id' => $payer->id],
            confirm: true,
        ));

        try {
            // The attacker is signed in as themselves and hands back the
            // reference. The provider will happily confirm the payment
            // succeeded — the question that matters is whose it is.
            app(ConfirmPaymentFromReturn::class)
                ->execute('fake', $confirmed->reference, $this->customer->id);
            $this->fail('A payment was attributed to a customer who did not make it.');
        } catch (PaymentAttributionMismatchException $e) {
            $this->assertSame('payment.attribution_mismatch', $e->errorCode());
            $this->assertSame(403, $e->httpStatus());
            $this->assertSame($this->customer->id, $e->context()['claimed_customer_id']);
        }

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_return_for_a_payment_the_provider_cannot_attribute_is_refused(): void
    {
        $provider = new FakePaymentProvider;

        // No attribution metadata at creation: a payment made outside the
        // platform, or a metadata bug. Either way the payer is unknown, and
        // the session's claim is not evidence of ownership.
        $confirmed = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: Money::ofMinor(9000, 'KWD'),
            idempotencyKey: 'idem_orphan_return',
            confirm: true,
        ));

        $this->expectException(UnattributablePaymentException::class);

        try {
            app(ConfirmPaymentFromReturn::class)
                ->execute('fake', $confirmed->reference, $this->customer->id);
        } finally {
            $this->assertSame(0, Transaction::query()->count());
        }
    }

    private function captureEvent(): ProviderEvent
    {
        return new ProviderEvent(
            providerEventId: 'evt_capture_1',
            type: 'payment.succeeded',
            kind: ProviderEventKind::PaymentSucceeded,
            amount: Money::ofMinor(9000, 'KWD'),
            currency: 'KWD',
            providerReference: 'fake_pi_succeeded_KWD_9000_abcdef12',
            payload: ['data' => ['reference' => 'fake_pi_succeeded_KWD_9000_abcdef12']],
            metadata: ['customer_id' => $this->customer->id],
        );
    }

    private function anOpenInvoice(): string
    {
        $id = (string) Str::ulid();

        DB::table('invoices')->insert([
            'id' => $id,
            'customer_id' => $this->customer->id,
            'number' => 'LYN-'.Str::upper(Str::random(10)),
            'status' => 'open',
            'currency' => 'KWD',
            'total_minor' => 9000,
            'billing_snapshot' => json_encode([]),
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
