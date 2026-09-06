<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Doubles;

use Lynomia\Modules\Payments\Domain\Contracts\PaymentProvider;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentResult;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\DTOs\RemotePaymentState;
use Lynomia\Modules\Payments\Domain\DTOs\RemoteRefundResult;
use Lynomia\Modules\Payments\Domain\DTOs\WebhookVerification;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use PHPUnit\Framework\Assert;

/**
 * The fake provider, wrapped so a test can see exactly what was asked of it.
 *
 * It delegates rather than reimplements: the question these tests ask is "what
 * did the platform send?", and answering it with a second, simpler fake would
 * mean the assertions were made against behaviour the real code path never
 * takes.
 *
 * It registers under the same name as the provider it wraps, so
 * PaymentProviderRegistry::swap() puts it in front of the driver the platform
 * has already chosen — no config change, and the transactions it produces are
 * still attributed to "fake".
 */
final class RecordingPaymentProvider implements PaymentProvider
{
    /** @var list<PaymentIntentRequest> */
    public array $intents = [];

    public function __construct(
        private readonly FakePaymentProvider $inner = new FakePaymentProvider,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function supportsCurrency(string $currency): bool
    {
        return $this->inner->supportsCurrency($currency);
    }

    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        $this->intents[] = $request;

        return $this->inner->createPaymentIntent($request);
    }

    public function retrievePayment(string $reference): RemotePaymentState
    {
        return $this->inner->retrievePayment($reference);
    }

    public function refund(string $chargeReference, Money $amount, string $reason, string $idempotencyKey): RemoteRefundResult
    {
        return $this->inner->refund($chargeReference, $amount, $reason, $idempotencyKey);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): WebhookVerification
    {
        return $this->inner->verifyWebhookSignature($rawPayload, $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhookEvent(array $payload): ?ProviderEvent
    {
        return $this->inner->parseWebhookEvent($payload);
    }

    /**
     * The single intent the platform asked for, failing loudly if it asked for
     * none or for several — a test that means "the one payment" should not
     * silently pass while two were created.
     */
    public function onlyIntent(): PaymentIntentRequest
    {
        Assert::assertCount(1, $this->intents, 'Expected exactly one payment intent to have been requested.');

        return $this->intents[0];
    }
}
