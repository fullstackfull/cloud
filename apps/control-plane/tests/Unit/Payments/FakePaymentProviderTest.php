<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Enums\RemotePaymentStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\UnsupportedCurrencyException;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fake provider is what every other payments test runs against, so its own
 * guarantees — determinism, real signatures, a decodable reference — have to
 * be established here rather than assumed everywhere else.
 */
final class FakePaymentProviderTest extends TestCase
{
    private FakePaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakePaymentProvider;
    }

    #[Test]
    public function an_ordinary_amount_is_accepted(): void
    {
        $result = $this->provider->createPaymentIntent($this->request(Money::ofMinor(9000, 'KWD'), confirm: true));

        $this->assertSame(RemotePaymentStatus::Succeeded, $result->status);
        $this->assertNull($result->failureCode);
        $this->assertTrue($result->succeeded());
    }

    #[Test]
    public function an_amount_ending_in_the_decline_suffix_is_refused_deterministically(): void
    {
        $declined = FakePaymentProvider::declineAmount(Money::ofMinor(9000, 'KWD'), 'insufficient_funds');

        $this->assertSame(9002, $declined->minorUnits());

        $first = $this->provider->createPaymentIntent($this->request($declined, confirm: true));
        $second = $this->provider->createPaymentIntent($this->request($declined, confirm: true));

        $this->assertSame(RemotePaymentStatus::Failed, $first->status);
        $this->assertSame('insufficient_funds', $first->failureCode);
        // Determinism is the whole point: the same request must produce the
        // same reference, or nothing keyed on it can be tested.
        $this->assertSame($first->reference, $second->reference);
    }

    #[Test]
    public function a_payment_can_be_retrieved_from_its_reference_alone(): void
    {
        $amount = Money::ofMinor(12500, 'KWD');
        $created = $this->provider->createPaymentIntent($this->request($amount, confirm: true));

        // A fresh instance stands in for a queue worker that never saw the
        // original request.
        $state = (new FakePaymentProvider)->retrievePayment($created->reference);

        $this->assertSame(RemotePaymentStatus::Succeeded, $state->status);
        $this->assertTrue($state->amount->equals($amount));
        $this->assertNotNull($state->chargeReference);
    }

    #[Test]
    public function a_currency_the_provider_does_not_settle_is_refused_before_anything_happens(): void
    {
        $this->expectException(UnsupportedCurrencyException::class);

        $this->provider->createPaymentIntent($this->request(Money::ofMinor(1000, 'JPY')));
    }

    #[Test]
    public function a_signed_webhook_verifies(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'fake_pi_succeeded_KWD_9000_abcdef12',
            Money::ofMinor(9000, 'KWD'),
        );

        $verification = $this->provider->verifyWebhookSignature($signed->rawPayload, $signed->headers);

        $this->assertTrue($verification->verified);
        $this->assertSame('fake.v1', $verification->verifiedBy);
    }

    #[Test]
    public function a_payload_edited_after_signing_does_not_verify(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'fake_pi_succeeded_KWD_9000_abcdef12',
            Money::ofMinor(9000, 'KWD'),
        );

        $tampered = str_replace('9000', '900000', $signed->rawPayload);
        $this->assertNotSame($signed->rawPayload, $tampered);

        $verification = $this->provider->verifyWebhookSignature($tampered, $signed->headers);

        $this->assertFalse($verification->verified);
        $this->assertNotNull($verification->reason);
    }

    #[Test]
    public function a_signature_older_than_the_tolerance_does_not_verify(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'fake_pi_succeeded_KWD_9000_abcdef12',
            Money::ofMinor(9000, 'KWD'),
            timestamp: time() - 4000,
        );

        // The signature itself is genuine — only the age is wrong, which is
        // exactly the shape of a captured request being replayed.
        $verification = $this->provider->verifyWebhookSignature($signed->rawPayload, $signed->headers);

        $this->assertFalse($verification->verified);
        $this->assertStringContainsString('tolerance', (string) $verification->reason);
    }

    #[Test]
    public function a_configured_tolerance_of_zero_does_not_disable_the_replay_window(): void
    {
        // A tolerance read straight from an unset environment variable is 0,
        // and 0 read literally means "accept any timestamp". The fake mirrors
        // the real providers here so the fail-open is caught in either.
        config(['payments.fake.webhook_tolerance' => 0]);
        $provider = new FakePaymentProvider;

        $signed = $provider->signPayload('{"id":"evt_1","type":"payment.succeeded"}', time() - 3600);

        $this->assertFalse($provider->verifyWebhookSignature($signed->rawPayload, $signed->headers)->verified);
    }

    #[Test]
    public function a_payload_with_no_signature_header_does_not_verify(): void
    {
        $verification = $this->provider->verifyWebhookSignature('{"id":"evt_1"}', []);

        $this->assertFalse($verification->verified);
    }

    #[Test]
    public function a_verified_payload_is_normalised_into_a_provider_event(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'fake_pi_succeeded_KWD_9000_abcdef12',
            Money::ofMinor(9000, 'KWD'),
            metadata: ['customer_id' => '01CUSTOMER0000000000000000'],
            eventId: 'evt_fake_normalise',
        );

        /** @var array<string, mixed> $payload */
        $payload = json_decode($signed->rawPayload, true);
        $event = $this->provider->parseWebhookEvent($payload);

        $this->assertNotNull($event);
        $this->assertSame('evt_fake_normalise', $event->providerEventId);
        $this->assertSame(ProviderEventKind::PaymentSucceeded, $event->kind);
        $this->assertSame('fake_pi_succeeded_KWD_9000_abcdef12', $event->providerReference);
        $this->assertSame('01CUSTOMER0000000000000000', $event->customerReference());
        $this->assertTrue($event->amount?->equals(Money::ofMinor(9000, 'KWD')));
    }

    #[Test]
    public function a_payload_without_an_event_id_cannot_be_parsed(): void
    {
        // No id means no replay key, so there is nothing to deduplicate on.
        $this->assertNull($this->provider->parseWebhookEvent(['type' => 'payment.succeeded']));
    }

    #[Test]
    public function an_unrecognised_event_type_is_normalised_to_unknown_rather_than_dropped(): void
    {
        $event = $this->provider->parseWebhookEvent(['id' => 'evt_1', 'type' => 'account.updated']);

        $this->assertNotNull($event);
        $this->assertSame(ProviderEventKind::Unknown, $event->kind);
        $this->assertFalse($event->kind->isActionable());
    }

    #[Test]
    public function a_refund_is_acknowledged(): void
    {
        $result = $this->provider->refund('fake_ch_abc', Money::ofMinor(2500, 'KWD'), 'requested_by_customer', 'ref_1');

        $this->assertSame(RefundStatus::Succeeded, $result->status);
        $this->assertStringStartsWith('fake_re_', $result->reference);
    }

    #[Test]
    public function two_refunds_of_the_same_amount_are_two_refunds(): void
    {
        // A charge legitimately refunded twice for the same amount and reason
        // — two returned line items, say. Nothing about the parameters
        // distinguishes them, so only the idempotency key can, and a provider
        // that keys on the parameters instead would answer the second call
        // with a replay of the first: one payout made, two recorded.
        $first = $this->provider->refund('fake_ch_abc', Money::ofMinor(2500, 'KWD'), 'requested_by_customer', 'ref_1');
        $second = $this->provider->refund('fake_ch_abc', Money::ofMinor(2500, 'KWD'), 'requested_by_customer', 'ref_2');

        $this->assertNotSame($first->reference, $second->reference);
    }

    #[Test]
    public function retrying_one_refund_is_the_same_refund(): void
    {
        $first = $this->provider->refund('fake_ch_abc', Money::ofMinor(2500, 'KWD'), 'requested_by_customer', 'ref_1');
        $retry = $this->provider->refund('fake_ch_abc', Money::ofMinor(2500, 'KWD'), 'requested_by_customer', 'ref_1');

        $this->assertSame($first->reference, $retry->reference);
    }

    private function request(Money $amount, bool $confirm = false): PaymentIntentRequest
    {
        return new PaymentIntentRequest(
            amount: $amount,
            idempotencyKey: 'idem_'.$amount->minorUnits().'_'.$amount->currency(),
            confirm: $confirm,
        );
    }
}
