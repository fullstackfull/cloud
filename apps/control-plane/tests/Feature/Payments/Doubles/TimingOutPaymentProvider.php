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
use Lynomia\Modules\Payments\Domain\Exceptions\PaymentProviderException;
use Lynomia\Modules\Payments\Infrastructure\Providers\FakePaymentProvider;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A provider that creates the intent and then stops answering.
 *
 * This is the shape of a real timeout, and the reason it needs a double of its
 * own: a provider that simply threw would be a provider that did nothing, and
 * the dangerous case is the opposite one — the intent exists at the provider,
 * the platform has stopped waiting, and the news about that intent will arrive
 * later by webhook. The double therefore delegates to the real fake first, keeps
 * the result the platform never saw, and only then throws.
 */
final class TimingOutPaymentProvider implements PaymentProvider
{
    /** @var list<PaymentIntentRequest> */
    public array $intents = [];

    /**
     * The results the platform never got to see, in call order.
     *
     * @var list<PaymentIntentResult>
     */
    public array $unseen = [];

    private bool $timeOutNextCall = false;

    public function __construct(
        private readonly FakePaymentProvider $inner = new FakePaymentProvider,
    ) {}

    /** The next createPaymentIntent call creates the intent and then times out. */
    public function timeOutNextCall(): void
    {
        $this->timeOutNextCall = true;
    }

    /** The reference of the intent the provider created but never reported. */
    public function unseenReference(): string
    {
        return $this->unseen[0]->reference;
    }

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

        $result = $this->inner->createPaymentIntent($request);

        if ($this->timeOutNextCall) {
            $this->timeOutNextCall = false;
            $this->unseen[] = $result;

            throw PaymentProviderException::requestFailed($this->name(), 'create_payment_intent');
        }

        return $result;
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
}
