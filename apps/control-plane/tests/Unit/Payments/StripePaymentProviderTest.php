<?php

declare(strict_types=1);

namespace Tests\Unit\Payments;

use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Enums\RemotePaymentStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\PaymentProviderException;
use Lynomia\Modules\Payments\Domain\Exceptions\UnsupportedCurrencyException;
use Lynomia\Modules\Payments\Infrastructure\Providers\StripePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * Exercises the Stripe adapter against canned API responses.
 *
 * The SDK's own HTTP client seam is used rather than a mock of StripeClient,
 * so every layer that matters runs for real: parameter serialisation, response
 * deserialisation into PaymentIntent objects, and — the part these tests are
 * actually here for — the SDK's exception hierarchy. Mocking StripeClient
 * would prove that the adapter handles exceptions we invented, not the ones
 * Stripe raises.
 */
final class StripePaymentProviderTest extends TestCase
{
    private const string API_KEY = 'sk_test_0000000000000000000000';

    protected function tearDown(): void
    {
        // The HTTP client is global static state in the SDK; leaving a stub
        // installed would silently affect any later test.
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    #[Test]
    public function a_created_payment_intent_is_mapped_into_domain_types(): void
    {
        $provider = $this->providerReturning(200, [
            'id' => 'pi_test_1',
            'object' => 'payment_intent',
            'amount' => 9000,
            'currency' => 'kwd',
            'status' => 'succeeded',
            'client_secret' => 'pi_test_1_secret_abcdefghijklmno',
            'latest_charge' => 'ch_test_1',
        ]);

        $result = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: Money::ofMinor(9000, 'KWD'),
            idempotencyKey: 'idem_1',
            metadata: ['customer_id' => '01CUSTOMER0000000000000000'],
            confirm: true,
        ));

        $this->assertSame('pi_test_1', $result->reference);
        $this->assertSame(RemotePaymentStatus::Succeeded, $result->status);
        // Stripe's amount is already minor units, so no conversion happens and
        // KWD's three decimal places survive the round trip untouched.
        $this->assertTrue($result->amount->equals(Money::ofMinor(9000, 'KWD')));
    }

    #[Test]
    public function the_stored_metadata_has_the_client_secret_stripped(): void
    {
        $provider = $this->providerReturning(200, [
            'id' => 'pi_test_1',
            'object' => 'payment_intent',
            'amount' => 9000,
            'currency' => 'kwd',
            'status' => 'requires_confirmation',
            'client_secret' => 'pi_test_1_secret_abcdefghijklmno',
        ]);

        $result = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: Money::ofMinor(9000, 'KWD'),
            idempotencyKey: 'idem_2',
        ));

        // The live secret is still handed to the caller for the browser…
        $this->assertSame('pi_test_1_secret_abcdefghijklmno', $result->clientSecret);
        // …but the copy destined for a database column is not.
        $this->assertSame(SecretRedactor::PLACEHOLDER, $result->metadata['client_secret']);
    }

    #[Test]
    public function a_card_decline_is_returned_as_a_result_rather_than_thrown(): void
    {
        $provider = $this->providerReturning(402, ['error' => [
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'insufficient_funds',
            'message' => 'Your card has insufficient funds.',
        ]]);

        $result = $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: Money::ofMinor(9000, 'KWD'),
            idempotencyKey: 'idem_3',
        ));

        $this->assertSame(RemotePaymentStatus::Failed, $result->status);
        // The decline code, not the generic error code: dunning branches on it.
        $this->assertSame('insufficient_funds', $result->failureCode);
        $this->assertFalse($result->succeeded());
    }

    #[Test]
    public function any_other_api_failure_becomes_a_domain_exception(): void
    {
        $provider = $this->providerReturning(404, ['error' => [
            'type' => 'invalid_request_error',
            'code' => 'resource_missing',
            'message' => "No such payment_intent: 'pi_missing'",
        ]]);

        try {
            $provider->retrievePayment('pi_missing');
            $this->fail('A Stripe API failure escaped as something other than a domain exception.');
        } catch (ApiErrorException) {
            $this->fail('A raw Stripe SDK exception escaped the adapter.');
        } catch (PaymentProviderException $e) {
            $this->assertSame('payment.provider_request_failed', $e->errorCode());
            $this->assertSame(502, $e->httpStatus());
            // The provider's own code is what an operator searches for.
            $this->assertSame('resource_missing', $e->context()['error_code']);
            $this->assertSame('retrieve_payment', $e->context()['operation']);
            $this->assertSame('pi_missing', $e->context()['provider_reference']);
        }
    }

    #[Test]
    public function a_provider_failure_never_carries_a_credential_in_its_message(): void
    {
        $provider = $this->providerReturning(401, ['error' => [
            'type' => 'invalid_request_error',
            'code' => 'api_key_expired',
            // Stripe echoes the offending key back; it must not survive into
            // our message, which is what ends up in logs and error responses.
            'message' => 'Expired API Key provided: '.self::API_KEY,
        ]]);

        try {
            $provider->retrievePayment('pi_test_1');
            $this->fail('The adapter did not raise on an authentication failure.');
        } catch (PaymentProviderException $e) {
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, (string) $e->context()['provider_message']);
            $this->assertStringContainsString(
                SecretRedactor::PLACEHOLDER,
                (string) $e->context()['provider_message'],
            );
        }
    }

    #[Test]
    public function a_refund_is_mapped_into_domain_types(): void
    {
        $provider = $this->providerReturning(200, [
            'id' => 're_test_1',
            'object' => 'refund',
            'amount' => 2500,
            'currency' => 'kwd',
            'status' => 'succeeded',
        ]);

        $result = $provider->refund('ch_test_1', Money::ofMinor(2500, 'KWD'), 'requested_by_customer');

        $this->assertSame('re_test_1', $result->reference);
        $this->assertSame(RefundStatus::Succeeded, $result->status);
        $this->assertTrue($result->amount->equals(Money::ofMinor(2500, 'KWD')));
    }

    #[Test]
    public function a_currency_stripe_is_not_configured_for_is_refused_before_the_request(): void
    {
        config(['services.stripe.currencies' => ['USD']]);
        $provider = $this->providerReturning(200, []);

        $this->expectException(UnsupportedCurrencyException::class);

        $provider->createPaymentIntent(new PaymentIntentRequest(
            amount: Money::ofMinor(9000, 'KWD'),
            idempotencyKey: 'idem_4',
        ));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function providerReturning(int $status, array $body): StripePaymentProvider
    {
        ApiRequestor::setHttpClient(new class($status, $body) implements ClientInterface
        {
            /**
             * @param  array<string, mixed>  $body
             */
            public function __construct(private int $status, private array $body) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return [json_encode($this->body, JSON_THROW_ON_ERROR), $this->status, []];
            }
        });

        return new StripePaymentProvider(
            new StripeClient(['api_key' => self::API_KEY]),
            new SecretRedactor(['secret', 'token', 'api_key', 'card', 'authorization']),
        );
    }
}
