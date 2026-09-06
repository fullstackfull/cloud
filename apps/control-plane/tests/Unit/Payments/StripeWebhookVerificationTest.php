<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Infrastructure\Providers\StripePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Stripe\StripeClient;
use Stripe\WebhookSignature;
use Tests\TestCase;

/**
 * Signature verification is delegated to the Stripe SDK, so these tests sign
 * payloads with the SDK's own signer and assert the adapter's answers.
 *
 * That is the point of signing them for real rather than stubbing the check:
 * the tampered and stale cases below are the two attacks a webhook endpoint
 * actually faces, and a stubbed verifier would pass them both.
 */
final class StripeWebhookVerificationTest extends TestCase
{
    private const string SECRET = 'whsec_test_0123456789abcdef0123456789abcdef';

    private StripePaymentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.webhook_secret' => self::SECRET,
            'services.stripe.webhook_tolerance' => 300,
        ]);

        $this->provider = new StripePaymentProvider(
            new StripeClient(['api_key' => 'sk_test_0000000000000000000000']),
            new SecretRedactor(['secret', 'token', 'api_key', 'card']),
        );
    }

    #[Test]
    public function a_genuinely_signed_payload_verifies(): void
    {
        $payload = $this->payload();

        $verification = $this->provider->verifyWebhookSignature($payload, $this->headersFor($payload));

        $this->assertTrue($verification->verified);
        $this->assertSame('stripe.v1', $verification->verifiedBy);
    }

    #[Test]
    public function a_payload_edited_after_signing_does_not_verify(): void
    {
        $payload = $this->payload();
        $headers = $this->headersFor($payload);

        // The amount an attacker would want to change, with the signature the
        // provider genuinely issued for the original.
        $tampered = str_replace('"amount": 9000', '"amount": 1', $payload);
        $this->assertNotSame($payload, $tampered);

        $verification = $this->provider->verifyWebhookSignature($tampered, $headers);

        $this->assertFalse($verification->verified);
    }

    #[Test]
    public function a_signature_older_than_the_configured_tolerance_does_not_verify(): void
    {
        $payload = $this->payload();

        $verification = $this->provider->verifyWebhookSignature(
            $payload,
            $this->headersFor($payload, time() - 3600),
        );

        $this->assertFalse($verification->verified);
        $this->assertStringContainsString('tolerance', (string) $verification->reason);
    }

    #[Test]
    public function the_tolerance_window_is_configurable(): void
    {
        config(['services.stripe.webhook_tolerance' => 7200]);
        $payload = $this->payload();

        $verification = $this->provider->verifyWebhookSignature(
            $payload,
            $this->headersFor($payload, time() - 3600),
        );

        $this->assertTrue($verification->verified);
    }

    #[Test]
    public function verification_fails_closed_when_no_signing_secret_is_configured(): void
    {
        config(['services.stripe.webhook_secret' => null]);
        $payload = $this->payload();

        // Without a secret every payload would otherwise "verify", which is
        // the worst possible way to be misconfigured.
        $verification = $this->provider->verifyWebhookSignature($payload, $this->headersFor($payload));

        $this->assertFalse($verification->verified);
        $this->assertStringContainsString('secret', (string) $verification->reason);
    }

    #[Test]
    public function a_payload_with_no_signature_header_does_not_verify(): void
    {
        $verification = $this->provider->verifyWebhookSignature($this->payload(), []);

        $this->assertFalse($verification->verified);
    }

    #[Test]
    public function the_signature_header_is_matched_case_insensitively(): void
    {
        $payload = $this->payload();
        $header = WebhookSignature::generateSignatureHeader($payload, self::SECRET);

        // Header casing depends on the web server in front of the app.
        $this->assertTrue($this->provider->verifyWebhookSignature($payload, ['Stripe-Signature' => $header])->verified);
        $this->assertTrue($this->provider->verifyWebhookSignature($payload, ['STRIPE-SIGNATURE' => [$header]])->verified);
    }

    #[Test]
    public function a_succeeded_payment_intent_is_normalised(): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->payload(), true);

        $event = $this->provider->parseWebhookEvent($decoded);

        $this->assertNotNull($event);
        $this->assertSame('evt_test_1', $event->providerEventId);
        $this->assertSame('payment_intent.succeeded', $event->type);
        $this->assertSame(ProviderEventKind::PaymentSucceeded, $event->kind);
        $this->assertSame('pi_test_1', $event->providerReference);
        $this->assertTrue($event->amount?->equals(Money::ofMinor(9000, 'KWD')));
        $this->assertSame('01CUSTOMER0000000000000000', $event->customerReference());
    }

    #[Test]
    public function a_refund_event_resolves_back_to_the_payment_intent_the_ledger_is_keyed_on(): void
    {
        $event = $this->provider->parseWebhookEvent([
            'id' => 'evt_refund_1',
            'type' => 'charge.refunded',
            'created' => time(),
            'data' => ['object' => [
                'id' => 'ch_test_1',
                'object' => 'charge',
                'payment_intent' => 'pi_test_1',
                'amount' => 9000,
                'amount_refunded' => 2500,
                'currency' => 'kwd',
            ]],
        ]);

        $this->assertNotNull($event);
        $this->assertSame(ProviderEventKind::RefundSucceeded, $event->kind);
        // The transaction row was created from the intent, not the charge.
        $this->assertSame('pi_test_1', $event->providerReference);
        $this->assertTrue($event->amount?->equals(Money::ofMinor(2500, 'KWD')));
    }

    #[Test]
    public function a_failed_payment_intent_carries_its_decline_code(): void
    {
        $event = $this->provider->parseWebhookEvent([
            'id' => 'evt_failed_1',
            'type' => 'payment_intent.payment_failed',
            'created' => time(),
            'data' => ['object' => [
                'id' => 'pi_test_2',
                'object' => 'payment_intent',
                'amount' => 9000,
                'currency' => 'kwd',
                'last_payment_error' => ['code' => 'card_declined', 'message' => 'Your card was declined.'],
            ]],
        ]);

        $this->assertNotNull($event);
        $this->assertSame(ProviderEventKind::PaymentFailed, $event->kind);
        $this->assertSame('card_declined', $event->failureCode);
    }

    #[Test]
    public function an_event_without_an_id_cannot_be_parsed(): void
    {
        $this->assertNull($this->provider->parseWebhookEvent([
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_x']],
        ]));
    }

    private function payload(): string
    {
        return <<<'JSON'
        {
            "id": "evt_test_1",
            "object": "event",
            "type": "payment_intent.succeeded",
            "created": 1735689600,
            "data": {
                "object": {
                    "id": "pi_test_1",
                    "object": "payment_intent",
                    "amount": 9000,
                    "amount_received": 9000,
                    "currency": "kwd",
                    "status": "succeeded",
                    "metadata": {"customer_id": "01CUSTOMER0000000000000000"}
                }
            }
        }
        JSON;
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(string $payload, ?int $timestamp = null): array
    {
        return [
            'stripe-signature' => WebhookSignature::generateSignatureHeader($payload, self::SECRET, $timestamp),
        ];
    }
}
