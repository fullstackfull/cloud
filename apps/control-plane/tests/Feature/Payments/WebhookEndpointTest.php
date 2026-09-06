<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Models\WebhookEvent;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Exercises the HTTP endpoint itself, not just the action behind it.
 *
 * The endpoint is where the pieces that are easy to get wrong actually meet:
 * the raw body has to survive the framework unmodified, the headers have to
 * reach the verifier in the shape it expects, and the status code has to be
 * the one that makes a provider stop retrying.
 */
final class WebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    private FakePaymentProvider $provider;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var FakePaymentProvider $provider */
        $provider = app(PaymentProviderRegistry::class)->get('fake');
        $this->provider = $provider;

        // A capture must be attributable to an account. The provider carries
        // the customer id in metadata, set when the payment intent is created,
        // and RecordPaymentCapture refuses a payment without one rather than
        // guessing — an unattributed capture is money the platform cannot
        // credit to anyone.
        $this->customer = Customer::factory()->create(['currency' => 'KWD']);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function attribution(array $extra = []): array
    {
        return ['customer_id' => $this->customer->id] + $extra;
    }

    private function deliver(string $rawPayload, array $headers): TestResponse
    {
        return $this->call(
            'POST',
            route('webhooks.receive', ['provider' => 'fake']),
            server: $this->serverHeaders($headers) + ['CONTENT_TYPE' => 'application/json'],
            content: $rawPayload,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    #[Test]
    public function a_correctly_signed_capture_is_accepted_and_recorded(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_fake_endpoint_1',
            Money::of('9.000', 'KWD'),
            metadata: $this->attribution(),
        );

        $this->deliver($signed->rawPayload, $signed->headers)
            ->assertOk()
            ->assertJsonPath('data.duplicate', false);

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(1, Transaction::query()->where('provider_reference', 'pi_fake_endpoint_1')->count());
    }

    #[Test]
    public function a_redelivered_event_returns_success_without_capturing_twice(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_fake_endpoint_2',
            Money::of('9.000', 'KWD'),
            metadata: $this->attribution(),
        );

        $this->deliver($signed->rawPayload, $signed->headers)->assertOk();

        // Providers redeliver routinely. A 4xx here would make the provider
        // retry an event that is already settled, forever.
        $this->deliver($signed->rawPayload, $signed->headers)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(1, Transaction::query()->where('provider_reference', 'pi_fake_endpoint_2')->count());
    }

    #[Test]
    public function an_unsigned_payload_is_rejected_and_nothing_is_stored(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_fake_unsigned',
            Money::of('9.000', 'KWD'),
            metadata: $this->attribution(),
        );

        $this->deliver($signed->rawPayload, [])->assertStatus(400);

        // Not even "for debugging". Storing an unverified payload is how a
        // forged event later gets replayed by an operator draining a queue.
        $this->assertSame(0, WebhookEvent::query()->count());
        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_tampered_body_fails_verification(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_fake_tampered',
            Money::of('9.000', 'KWD'),
            metadata: $this->attribution(),
        );

        // The signature is over the bytes; changing the amount must break it.
        $tampered = str_replace('9000', '900000', $signed->rawPayload);
        $this->assertNotSame($signed->rawPayload, $tampered);

        $this->deliver($tampered, $signed->headers)->assertStatus(400);

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function a_stale_but_correctly_signed_payload_is_rejected(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_fake_stale',
            Money::of('9.000', 'KWD'),
            metadata: $this->attribution(),
            timestamp: time() - 86_400,
        );

        // A valid signature is not enough on its own: without a tolerance
        // window a captured request replays indefinitely.
        $this->deliver($signed->rawPayload, $signed->headers)->assertStatus(400);

        $this->assertSame(0, Transaction::query()->count());
    }

    #[Test]
    public function an_unknown_provider_is_a_404_rather_than_a_container_error(): void
    {
        $this->call('POST', '/webhooks/not-a-provider', content: '{}')
            ->assertStatus(404);
    }

    #[Test]
    public function the_endpoint_requires_no_csrf_token(): void
    {
        // A provider is not a browser. Requiring a CSRF token here would mean
        // the endpoint simply never worked in production.
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_fake_no_csrf',
            Money::of('9.000', 'KWD'),
            metadata: $this->attribution(),
        );

        $this->deliver($signed->rawPayload, $signed->headers)->assertOk();
    }

    #[Test]
    public function a_declined_payment_is_recorded_without_a_successful_transaction(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentFailed,
            'pi_fake_declined',
            Money::of('9.000', 'KWD'),
            metadata: $this->attribution(),
            failureCode: 'card_declined',
        );

        $this->deliver($signed->rawPayload, $signed->headers)->assertOk();

        $transaction = Transaction::query()->where('provider_reference', 'pi_fake_declined')->sole();
        $this->assertSame('failed', $transaction->status->value);
        $this->assertSame('card_declined', $transaction->failure_code);
    }

    #[Test]
    public function the_stored_payload_carries_no_secret(): void
    {
        $signed = $this->provider->emitWebhook(
            ProviderEventKind::PaymentSucceeded,
            'pi_fake_secrets',
            Money::of('9.000', 'KWD'),
            metadata: $this->attribution(['api_key' => 'sk_live_should_never_be_stored']),
        );

        $this->deliver($signed->rawPayload, $signed->headers)->assertOk();

        $stored = json_encode(WebhookEvent::query()->sole()->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sk_live_should_never_be_stored', $stored);
    }
}
