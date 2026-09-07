<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Application\Actions\IngestWebhookEvent;
use Lynomia\Modules\Payments\Domain\DTOs\SignedWebhookPayload;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\WebhookEventStatus;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Domain\Events\PaymentFailed;
use Lynomia\Modules\Payments\Domain\Exceptions\MalformedWebhookPayloadException;
use Lynomia\Modules\Payments\Domain\Exceptions\WebhookSignatureException;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Models\WebhookEvent;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SecretFixtures;
use Tests\TestCase;

/**
 * Webhook ingestion is where forged and replayed events are stopped, so these
 * tests are written as the attacks rather than as the happy path: a payload
 * edited after signing, a captured request replayed later, and the same
 * genuine event delivered twice at once.
 */
final class IngestWebhookEventTest extends TestCase
{
    use RefreshDatabase;

    private const string REFERENCE = 'fake_pi_succeeded_KWD_9000_abcdef12';

    private IngestWebhookEvent $ingest;

    private FakePaymentProvider $provider;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingest = app(IngestWebhookEvent::class);
        $this->provider = new FakePaymentProvider;
        $this->customer = Customer::factory()->create();
    }

    #[Test]
    public function a_verified_capture_is_recorded_once(): void
    {
        Event::fake([PaymentCaptured::class]);

        $result = $this->ingest->execute('fake', ...$this->deliver($this->signedCapture()));

        $this->assertFalse($result->duplicate);
        $this->assertNotNull($result->transaction);
        $this->assertSame(TransactionStatus::Succeeded, $result->transaction->status);
        $this->assertTrue($result->transaction->amount()->equals(Money::ofMinor(9000, 'KWD')));
        $this->assertSame(WebhookEventStatus::Processed, $result->event->status);

        Event::assertDispatched(PaymentCaptured::class);
    }

    #[Test]
    public function a_duplicate_webhook_event_is_a_no_op(): void
    {
        $signed = $this->signedCapture();

        $first = $this->ingest->execute('fake', ...$this->deliver($signed));
        $second = $this->ingest->execute('fake', ...$this->deliver($signed));

        $this->assertFalse($first->duplicate);
        $this->assertTrue($second->duplicate);

        // The point of the whole design: one event id, one capture.
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame($first->transaction?->id, $second->transaction?->id);
    }

    #[Test]
    public function a_duplicate_does_not_dispatch_a_second_capture_event(): void
    {
        $signed = $this->signedCapture();
        $this->ingest->execute('fake', ...$this->deliver($signed));

        // Faked only now: a listener wired to PaymentCaptured would otherwise
        // settle the same invoice twice on a redelivery.
        Event::fake([PaymentCaptured::class]);
        $this->ingest->execute('fake', ...$this->deliver($signed));

        Event::assertNotDispatched(PaymentCaptured::class);
    }

    #[Test]
    public function concurrent_redelivery_does_not_double_capture(): void
    {
        $signed = $this->signedCapture();
        $interleaved = false;

        /*
         * Drop the redelivery into the worst possible moment: it is ingested
         * to completion between the first worker's "does a transaction exist?"
         * read and its insert. That is precisely the window a check-then-
         * insert cannot close, so the unique index has to — the first worker's
         * insert is rejected, and it converges on the row instead of adding a
         * second capture.
         *
         * Both workers share one connection here, so the loser's rollback
         * unwinds to a savepoint taken before the winner ran; which of the two
         * rows survives is therefore an artefact of the simulation, and the
         * assertions deliberately only claim what is true either way.
         */
        Event::listen('eloquent.creating: '.Transaction::class, function () use ($signed, &$interleaved): void {
            if ($interleaved) {
                return;
            }

            $interleaved = true;
            $this->ingest->execute('fake', ...$this->deliver($signed));
        });

        $result = $this->ingest->execute('fake', ...$this->deliver($signed));

        $this->assertTrue($interleaved, 'The redelivery was never interleaved with the first capture.');
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(1, WebhookEvent::query()->count());

        $transaction = Transaction::query()->sole();
        $this->assertSame(TransactionStatus::Succeeded, $transaction->status);
        $this->assertSame(self::REFERENCE, $transaction->provider_reference);
        $this->assertTrue($transaction->amount()->equals(Money::ofMinor(9000, 'KWD')));
        $this->assertSame($transaction->id, $result->transaction?->id);
    }

    #[Test]
    public function the_database_itself_refuses_a_second_capture_for_the_same_provider_reference(): void
    {
        // The replay defence is a constraint, not a code path: if this index
        // is ever dropped, every guard above it becomes advisory.
        $this->ingest->execute('fake', ...$this->deliver($this->signedCapture()));

        $this->expectException(UniqueConstraintViolationException::class);

        Transaction::factory()->forCustomer($this->customer)->create([
            'provider' => 'fake',
            'provider_reference' => self::REFERENCE,
        ]);
    }

    #[Test]
    public function a_capture_already_in_the_ledger_is_converged_on_rather_than_duplicated(): void
    {
        // A different event id for the same payment: providers do send
        // payment_intent.succeeded and a charge event for one capture.
        $existing = Transaction::factory()->forCustomer($this->customer)->create([
            'provider' => 'fake',
            'provider_reference' => self::REFERENCE,
        ]);

        $result = $this->ingest->execute('fake', ...$this->deliver($this->signedCapture()));

        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame($existing->id, $result->transaction?->id);
    }

    #[Test]
    public function an_invalid_signature_is_rejected_and_nothing_is_stored_as_trusted(): void
    {
        $signed = $this->signedCapture();

        try {
            $this->ingest->execute('fake', $signed->rawPayload, [
                FakePaymentProvider::SIGNATURE_HEADER => 't='.time().',v1='.str_repeat('0', 64),
            ]);
            $this->fail('A forged webhook was accepted.');
        } catch (WebhookSignatureException $e) {
            $this->assertSame('payment.webhook_signature_invalid', $e->errorCode());
            $this->assertSame(400, $e->httpStatus());
        }

        // Not stored, not parsed, not acted on. An unverified payload that
        // reaches the database is one an operator can later replay by hand.
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_tampered_payload_fails_verification(): void
    {
        $signed = $this->signedCapture();
        $tampered = str_replace('"amount_minor":9000', '"amount_minor":900000', $signed->rawPayload);

        $this->assertNotSame($signed->rawPayload, $tampered);

        $this->expectException(WebhookSignatureException::class);

        try {
            $this->ingest->execute('fake', $tampered, $signed->headers);
        } finally {
            $this->assertSame(0, WebhookEvent::query()->count());
            $this->assertSame(0, Transaction::query()->count());
        }
    }

    #[Test]
    public function a_stale_webhook_timestamp_is_rejected(): void
    {
        // A genuine signature, captured an hour ago and replayed now.
        $signed = $this->signedCapture(timestamp: time() - 3600);

        $this->expectException(WebhookSignatureException::class);

        try {
            $this->ingest->execute('fake', ...$this->deliver($signed));
        } finally {
            $this->assertSame(0, WebhookEvent::query()->count());
            $this->assertSame(0, Transaction::query()->count());
        }
    }

    #[Test]
    public function a_body_with_no_event_id_is_refused_because_it_cannot_be_deduplicated(): void
    {
        $signed = $this->provider->signPayload('{"type":"payment.succeeded"}');

        $this->expectException(MalformedWebhookPayloadException::class);

        $this->ingest->execute('fake', ...$this->deliver($signed));
    }

    #[Test]
    public function a_declined_payment_records_a_failure_code_and_no_succeeded_transaction(): void
    {
        $declined = FakePaymentProvider::declineAmount(Money::ofMinor(9000, 'KWD'), 'insufficient_funds');

        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentFailed,
            'fake_pi_failed_KWD_9002_abcdef12',
            $declined,
            metadata: ['customer_id' => $this->customer->id],
            eventId: 'evt_declined_1',
            failureCode: 'insufficient_funds',
        );

        $result = $this->ingest->execute('fake', ...$this->deliver($signed));

        $transaction = $result->transaction;
        $this->assertNotNull($transaction);
        $this->assertSame(TransactionStatus::Failed, $transaction->status);
        $this->assertSame('insufficient_funds', $transaction->failure_code);
        $this->assertSame(0, Transaction::query()->where('status', TransactionStatus::Succeeded->value)->count());
    }

    #[Test]
    public function a_failure_arriving_after_the_capture_neither_downgrades_it_nor_starts_dunning(): void
    {
        $this->ingest->execute('fake', ...$this->deliver($this->signedCapture()));

        /*
         * Providers deliver these out of order, and send a
         * payment_intent.payment_failed for an earlier attempt on an intent
         * that has since succeeded. The ledger must not un-pay the capture,
         * and — the part a row-level guard alone does not give you — nothing
         * downstream may be told the payment failed: PaymentFailed is what
         * drives dunning emails, retry scheduling and suspension timers.
         */
        Event::fake([PaymentFailed::class]);

        $late = $this->provider->emitWebhook(
            ProviderEventKind::PaymentFailed,
            self::REFERENCE,
            Money::ofMinor(9000, 'KWD'),
            metadata: ['customer_id' => $this->customer->id],
            eventId: 'evt_late_failure_1',
            failureCode: 'card_declined',
        );

        $result = $this->ingest->execute('fake', ...$this->deliver($late));

        Event::assertNotDispatched(PaymentFailed::class);
        $this->assertSame(1, Transaction::query()->count());

        $transaction = Transaction::query()->sole();
        $this->assertSame(TransactionStatus::Succeeded, $transaction->status);
        $this->assertNull($transaction->failure_code);
        $this->assertSame($transaction->id, $result->transaction?->id);
    }

    #[Test]
    public function an_event_type_the_platform_does_not_act_on_is_recorded_and_ignored(): void
    {
        $signed = $this->provider->signPayload(
            json_encode(['id' => 'evt_ping_1', 'type' => 'diagnostic.ping'], JSON_THROW_ON_ERROR),
        );

        $result = $this->ingest->execute('fake', ...$this->deliver($signed));

        $this->assertSame(WebhookEventStatus::Ignored, $result->event->status);
        $this->assertNull($result->transaction);
        // Acknowledged so the provider stops redelivering it, and visible so
        // an operator can see what was actually sent.
        $this->assertSame(1, WebhookEvent::query()->count());
    }

    #[Test]
    public function a_failure_records_the_attempt_and_the_error_so_it_can_be_retried(): void
    {
        // No customer metadata: the capture cannot be attributed to anyone.
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            self::REFERENCE,
            Money::ofMinor(9000, 'KWD'),
            eventId: 'evt_unattributable_1',
        );

        try {
            $this->ingest->execute('fake', ...$this->deliver($signed));
            $this->fail('An unattributable capture was accepted.');
        } catch (\Throwable) {
            // The throw is expected; what matters is what survived it.
        }

        $event = WebhookEvent::query()->sole();
        $this->assertSame(WebhookEventStatus::Failed, $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->last_error);
        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_failed_event_can_be_retried_and_settles_on_the_second_attempt(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            self::REFERENCE,
            Money::ofMinor(9000, 'KWD'),
            eventId: 'evt_retry_1',
        );

        try {
            $this->ingest->execute('fake', ...$this->deliver($signed));
        } catch (\Throwable) {
            // First attempt fails: nothing identifies the payer yet.
        }

        // The operator attaches the payment to a customer and the event is
        // redelivered; a failed event is not settled, so it is handled again.
        $repaired = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            self::REFERENCE,
            Money::ofMinor(9000, 'KWD'),
            metadata: ['customer_id' => $this->customer->id],
            eventId: 'evt_retry_1',
        );

        $result = $this->ingest->execute('fake', ...$this->deliver($repaired));

        $this->assertFalse($result->duplicate);
        $this->assertSame(WebhookEventStatus::Processed, $result->event->status);
        $this->assertSame(2, $result->event->attempts);
        $this->assertNull($result->event->last_error);
        $this->assertSame(1, Transaction::query()->count());
    }

    #[Test]
    public function the_stored_payload_and_transaction_metadata_have_their_secrets_redacted(): void
    {
        $payload = json_encode([
            'id' => 'evt_secrets_1',
            'type' => 'payment.succeeded',
            'created' => time(),
            'data' => [
                'reference' => self::REFERENCE,
                'amount_minor' => 9000,
                'currency' => 'KWD',
                'metadata' => ['customer_id' => $this->customer->id],
                'client_secret' => 'pi_live_secret_abcdefghijklmnop',
                'note' => 'authorised with '.SecretFixtures::STRIPE_SECRET_KEY,
            ],
        ], JSON_THROW_ON_ERROR);

        $result = $this->ingest->execute('fake', ...$this->deliver($this->provider->signPayload($payload)));

        $stored = $result->event->payload['data'] ?? [];
        // Key-based redaction catches the credential field…
        $this->assertSame(SecretRedactor::PLACEHOLDER, $stored['client_secret']);
        // …and pattern-based redaction catches the key pasted into free text.
        $this->assertStringNotContainsString(SecretFixtures::STRIPE_SECRET_KEY, (string) $stored['note']);

        $metadata = $result->transaction?->provider_metadata ?? [];
        $this->assertSame(SecretRedactor::PLACEHOLDER, $metadata['object']['client_secret']);
        // The attribution metadata is not a secret and must survive.
        $this->assertSame($this->customer->id, $metadata['object']['metadata']['customer_id']);
    }

    private function signedCapture(?int $timestamp = null): SignedWebhookPayload
    {
        return $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            self::REFERENCE,
            Money::ofMinor(9000, 'KWD'),
            metadata: ['customer_id' => $this->customer->id],
            eventId: 'evt_capture_1',
            timestamp: $timestamp,
        );
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function deliver(SignedWebhookPayload $signed): array
    {
        return [$signed->rawPayload, $signed->headers];
    }
}
