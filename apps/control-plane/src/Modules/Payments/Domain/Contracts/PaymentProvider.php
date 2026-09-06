<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Domain\Contracts;

use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentResult;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\DTOs\RemotePaymentState;
use Lynomia\Modules\Payments\Domain\DTOs\RemoteRefundResult;
use Lynomia\Modules\Payments\Domain\DTOs\WebhookVerification;
use Lynomia\Modules\Payments\Domain\Exceptions\PaymentProviderException;
use Lynomia\Modules\Payments\Domain\Exceptions\UnsupportedCurrencyException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * Everything the platform is allowed to ask a payment provider to do.
 *
 * The interface is deliberately small and stated entirely in domain types.
 * Nothing above this line may accept a provider SDK object, catch a provider
 * SDK exception, or know that Stripe exists — that constraint is what makes a
 * second provider an additive change rather than a rewrite of billing.
 *
 * Two rules bind every implementation:
 *
 *  - failures throw {@see PaymentProviderException}, never an SDK exception,
 *    and never with a credential in the message;
 *  - a decline is not a failure. It is returned as a result with a code, so
 *    the caller can distinguish "the customer's bank said no" from "we could
 *    not reach the provider", which have opposite retry behaviour.
 */
interface PaymentProvider
{
    /**
     * The stable key this provider is registered and persisted under. It is
     * written into transactions.provider, so it must never change once a row
     * exists.
     */
    public function name(): string;

    public function supportsCurrency(string $currency): bool;

    /**
     * @throws UnsupportedCurrencyException
     * @throws PaymentProviderException
     */
    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult;

    /**
     * Ask the provider what actually happened to a payment.
     *
     * This is the trusted alternative to a webhook, and the only thing a
     * browser redirect handler is permitted to believe.
     *
     * @throws PaymentProviderException
     */
    public function retrievePayment(string $reference): RemotePaymentState;

    /**
     * @param  string  $chargeReference  The captured payment to refund against.
     *
     * @throws PaymentProviderException
     */
    public function refund(string $chargeReference, Money $amount, string $reason): RemoteRefundResult;

    /**
     * Decide whether a raw request body genuinely came from this provider.
     *
     * Takes the raw body rather than a decoded array on purpose: signatures
     * are computed over the exact bytes, and a decode/re-encode round trip
     * changes them.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers): WebhookVerification;

    /**
     * Normalise a verified payload into a ProviderEvent.
     *
     * Returns null when the payload is not an event this provider recognises
     * at all. An event that is recognised but not acted on comes back with
     * kind Unknown instead, so that it can still be recorded and deduplicated.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhookEvent(array $payload): ?ProviderEvent;
}
