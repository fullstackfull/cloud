<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure\Providers;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Payments\Domain\Contracts\PaymentProvider;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentResult;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\DTOs\RemotePaymentState;
use Lynomia\Modules\Payments\Domain\DTOs\RemoteRefundResult;
use Lynomia\Modules\Payments\Domain\DTOs\WebhookVerification;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Enums\RemotePaymentStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\PaymentProviderException;
use Lynomia\Modules\Payments\Domain\Exceptions\UnsupportedCurrencyException;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\PaymentIntent;
use Stripe\Refund as StripeRefund;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe, via the official SDK.
 *
 * Three things about this adapter are deliberate.
 *
 * Amounts cross the boundary as integer minor units in both directions.
 * Stripe's `amount` is already the smallest currency unit, which is exactly
 * what Money stores, so there is no decimal conversion anywhere in this file
 * and therefore no place for a rounding error to enter. That holds for KWD's
 * three decimal places as much as for USD's two.
 *
 * A card decline is returned, not thrown. Stripe raises CardException for it,
 * but "the issuer said no" is an answer to our question, and dunning needs the
 * decline code far more than it needs a stack trace. Everything else — auth
 * failures, rate limits, malformed requests, connection errors — becomes a
 * PaymentProviderException, so no Stripe class escapes this file. That matters
 * beyond tidiness: ApiErrorException carries the HTTP body of the failed
 * request, and letting it propagate is how an API key ends up in a log.
 *
 * Webhook verification delegates to the SDK rather than reimplementing HMAC
 * comparison. Stripe's implementation already does constant-time comparison,
 * multi-signature handling for key rotation, and the timestamp tolerance that
 * bounds replay; a hand-rolled version would be a strictly worse copy.
 */
final class StripePaymentProvider implements PaymentProvider
{
    public const string NAME = 'stripe';

    public const string SIGNATURE_HEADER = 'stripe-signature';

    /** Stripe's own default replay window, in seconds. */
    private const int DEFAULT_WEBHOOK_TOLERANCE = 300;

    /**
     * The only values Stripe accepts for a refund's `reason`. Anything else we
     * want to record travels in metadata instead of being rejected by the API.
     *
     * @var list<string>
     */
    private const array REFUND_REASONS = ['duplicate', 'fraudulent', 'requested_by_customer'];

    public function __construct(
        private readonly StripeClient $client,
        private readonly SecretRedactor $redactor,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function supportsCurrency(string $currency): bool
    {
        /** @var list<string> $supported */
        $supported = config('services.stripe.currencies', ['KWD', 'USD', 'EUR', 'GBP', 'SAR', 'AED']);

        return in_array(strtoupper($currency), array_map(strtoupper(...), $supported), true);
    }

    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        $this->assertSupportedCurrency($request->amount->currency());

        $params = array_filter([
            'amount' => $request->amount->minorUnits(),
            'currency' => strtolower($request->amount->currency()),
            'customer' => $request->customerReference,
            'payment_method' => $request->paymentMethodReference,
            'description' => $request->description,
            'metadata' => $request->metadata,
            'confirm' => $request->confirm ?: null,
            'off_session' => $request->offSession ?: null,
            'return_url' => $request->returnUrl,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        try {
            $intent = $this->client->paymentIntents->create(
                $params,
                // Stripe deduplicates on this key for 24 hours, which is what
                // makes a retry after a timeout safe: the second request
                // returns the first one's intent instead of creating another.
                ['idempotency_key' => $request->idempotencyKey],
            );
        } catch (CardException $e) {
            return $this->declinedResult($request, $e);
        } catch (ApiErrorException $e) {
            throw $this->translate($e, 'create_payment_intent');
        }

        /*
         * Read the response as an array rather than through the SDK's magic
         * properties: accessing a field Stripe did not return emits a notice
         * to stderr, and half of these fields are absent on any given intent.
         */
        $data = $intent->toArray();

        return new PaymentIntentResult(
            reference: (string) ($data['id'] ?? ''),
            status: self::mapIntentStatus((string) ($data['status'] ?? '')),
            amount: Money::ofMinor((int) ($data['amount'] ?? 0), (string) ($data['currency'] ?? 'usd')),
            clientSecret: self::stringOrNull($data['client_secret'] ?? null),
            nextActionUrl: self::nextActionUrl($data),
            failureCode: self::errorField($data, 'code'),
            failureMessage: self::errorField($data, 'message'),
            metadata: $this->safeMetadata($data),
        );
    }

    public function retrievePayment(string $reference): RemotePaymentState
    {
        try {
            // The charge is what a refund is issued against, and it is not the
            // intent id; expanding it here saves a second round trip on the
            // refund path.
            $intent = $this->client->paymentIntents->retrieve($reference, ['expand' => ['latest_charge']]);
        } catch (ApiErrorException $e) {
            throw $this->translate($e, 'retrieve_payment', ['provider_reference' => $reference]);
        }

        $data = $intent->toArray();

        return new RemotePaymentState(
            reference: (string) ($data['id'] ?? ''),
            status: self::mapIntentStatus((string) ($data['status'] ?? '')),
            amount: Money::ofMinor((int) ($data['amount'] ?? 0), (string) ($data['currency'] ?? 'usd')),
            chargeReference: self::chargeReference($data),
            failureCode: self::errorField($data, 'code'),
            failureMessage: self::errorField($data, 'message'),
            metadata: $this->safeMetadata($data),
        );
    }

    public function refund(string $chargeReference, Money $amount, string $reason, string $idempotencyKey): RemoteRefundResult
    {
        $this->assertSupportedCurrency($amount->currency());

        $params = [
            // Stripe accepts either a charge or a payment intent here, so the
            // caller may pass whichever reference it holds.
            str_starts_with($chargeReference, 'pi_') ? 'payment_intent' : 'charge' => $chargeReference,
            'amount' => $amount->minorUnits(),
            'metadata' => ['reason' => $reason],
        ];

        if (in_array($reason, self::REFUND_REASONS, true)) {
            $params['reason'] = $reason;
        }

        try {
            $refund = $this->client->refunds->create($params, [
                /*
                 * The caller's key, which identifies one refund row rather
                 * than one set of parameters. Hashing the parameters instead
                 * would be actively dangerous: two deliberate partial refunds
                 * of the same amount, for the same reason, against the same
                 * charge would produce one key, and Stripe would answer the
                 * second with a replay of the first — one payout made, two
                 * recorded, and the customer short the difference.
                 */
                'idempotency_key' => self::NAME.'_refund_'.$idempotencyKey,
            ]);
        } catch (ApiErrorException $e) {
            throw $this->translate($e, 'refund', ['provider_reference' => $chargeReference]);
        }

        $data = $refund->toArray();

        return new RemoteRefundResult(
            reference: (string) ($data['id'] ?? ''),
            status: self::mapRefundStatus(self::stringOrNull($data['status'] ?? null)),
            amount: Money::ofMinor((int) ($data['amount'] ?? 0), (string) ($data['currency'] ?? 'usd')),
            failureReason: self::stringOrNull($data['failure_reason'] ?? null),
            metadata: $this->safeMetadata($data),
        );
    }

    public function verifyWebhookSignature(string $rawPayload, array $headers): WebhookVerification
    {
        $header = self::header($headers, self::SIGNATURE_HEADER);

        if ($header === null) {
            return WebhookVerification::failed('no Stripe-Signature header was presented');
        }

        $secret = (string) config('services.stripe.webhook_secret');

        if ($secret === '') {
            // Without a secret every payload would verify, so refuse rather
            // than fail open.
            return WebhookVerification::failed('no webhook signing secret is configured');
        }

        try {
            Webhook::constructEvent($rawPayload, $header, $secret, self::webhookTolerance());
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            // Stripe's message names the failure — bad signature, stale
            // timestamp, unparseable body — without echoing the secret.
            return WebhookVerification::failed($this->redactor->redactString($e->getMessage()));
        }

        return WebhookVerification::verified('stripe.v1');
    }

    public function parseWebhookEvent(array $payload): ?ProviderEvent
    {
        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;

        // No id means no replay key. An event we cannot deduplicate is an
        // event we must not act on.
        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '') {
            return null;
        }

        /** @var array<string, mixed> $object */
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

        $kind = self::kindFor($type);
        $currency = is_string($object['currency'] ?? null) ? strtoupper((string) $object['currency']) : null;
        $minor = self::amountMinorFor($kind, $object);

        /** @var array<string, string> $metadata */
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        return new ProviderEvent(
            providerEventId: $id,
            type: $type,
            kind: $kind,
            amount: $currency !== null && $minor !== null ? Money::ofMinor($minor, $currency) : null,
            currency: $currency,
            providerReference: self::referenceFor($kind, $object),
            payload: $payload,
            metadata: $metadata,
            failureCode: self::errorField($object, 'code'),
            failureMessage: self::errorField($object, 'message'),
            occurredAt: isset($payload['created']) && is_numeric($payload['created'])
                ? CarbonImmutable::createFromTimestampUTC((int) $payload['created'])
                : null,
        );
    }

    /**
     * The replay window, clamped to a positive value.
     *
     * The SDK treats a tolerance of zero as "skip the timestamp check
     * entirely", and zero is exactly what an unset or non-numeric environment
     * variable casts to. That failure is silent and total — every captured
     * request stays replayable forever — so a non-positive configured value is
     * read as a misconfiguration and the default is used instead.
     */
    private static function webhookTolerance(): int
    {
        $configured = (int) config('services.stripe.webhook_tolerance', self::DEFAULT_WEBHOOK_TOLERANCE);

        return $configured > 0 ? $configured : self::DEFAULT_WEBHOOK_TOLERANCE;
    }

    private function assertSupportedCurrency(string $currency): void
    {
        if (! $this->supportsCurrency($currency)) {
            throw UnsupportedCurrencyException::forProvider(self::NAME, $currency);
        }
    }

    /**
     * A decline is an outcome, so it is shaped like one.
     *
     * The exception still carries a PaymentIntent, so the reference is real
     * and the payment can be retrieved and retried later.
     */
    private function declinedResult(PaymentIntentRequest $request, CardException $e): PaymentIntentResult
    {
        $error = $e->getError();
        $intent = $error?->payment_intent?->id;

        // Hoisted rather than read inside the `??` chain below: a `?->` on the
        // left of `??` is redundant, because `??` already absorbs the null, and
        // writing both reads as though one of them were load-bearing.
        $declineCode = $error?->decline_code;

        return new PaymentIntentResult(
            reference: is_string($intent) && $intent !== ''
                ? $intent
                : 'pi_declined_'.substr(hash('sha256', $request->idempotencyKey), 0, 16),
            status: RemotePaymentStatus::Failed,
            amount: $request->amount,
            // A decline code is more specific than the error code and is what
            // dunning branches on, so prefer it when the issuer supplied one.
            failureCode: $declineCode ?? $e->getStripeCode() ?? 'card_declined',
            failureMessage: $this->redactor->redactString((string) $e->getMessage()),
            metadata: $this->safeMetadata(['declined' => true, 'error_type' => $error?->type]),
        );
    }

    /**
     * Turn any Stripe API failure into a domain exception.
     *
     * The provider's own error code is preserved in the context because it is
     * what an operator searches for, while the message is one we wrote: the
     * SDK's message and http body routinely quote the failed request, which is
     * the request that carried the API key.
     *
     * @param  array<string, scalar|null>  $context
     */
    private function translate(ApiErrorException $e, string $operation, array $context = []): PaymentProviderException
    {
        $error = $e->getError();

        // Hoisted for the same reason as in declinedResult(): `?->` on the left
        // of `??` is redundant.
        $providerMessage = $error?->message;

        return PaymentProviderException::requestFailed(
            self::NAME,
            $operation,
            [
                ...$context,
                'error_code' => $e->getStripeCode(),
                'error_type' => $error?->type,
                'decline_code' => $error?->decline_code,
                'http_status' => $e->getHttpStatus(),
                'request_id' => $e->getRequestId(),
                'provider_message' => $this->redactor->redactString((string) ($providerMessage ?? $e->getMessage())),
            ],
            previous: $e,
        );
    }

    /**
     * Provider payloads are stored to explain what happened, so they are
     * redacted here rather than trusted to be clean — a PaymentIntent carries
     * a client_secret, which is a bearer credential for that payment.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function safeMetadata(array $payload): array
    {
        return $this->redactor->redact($payload);
    }

    private static function mapIntentStatus(string $status): RemotePaymentStatus
    {
        return match ($status) {
            PaymentIntent::STATUS_SUCCEEDED => RemotePaymentStatus::Succeeded,
            PaymentIntent::STATUS_CANCELED => RemotePaymentStatus::Cancelled,
            PaymentIntent::STATUS_PROCESSING => RemotePaymentStatus::Processing,
            PaymentIntent::STATUS_REQUIRES_ACTION,
            PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
            PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
            // An authorised-but-uncaptured intent is not money we hold; it is
            // an instruction we have not yet acted on.
            PaymentIntent::STATUS_REQUIRES_CAPTURE => RemotePaymentStatus::RequiresAction,
            default => RemotePaymentStatus::Processing,
        };
    }

    private static function mapRefundStatus(?string $status): RefundStatus
    {
        return match ($status) {
            StripeRefund::STATUS_SUCCEEDED => RefundStatus::Succeeded,
            StripeRefund::STATUS_FAILED => RefundStatus::Failed,
            StripeRefund::STATUS_CANCELED => RefundStatus::Cancelled,
            default => RefundStatus::Pending,
        };
    }

    private static function kindFor(string $type): ProviderEventKind
    {
        return match ($type) {
            'payment_intent.succeeded' => ProviderEventKind::PaymentSucceeded,
            'payment_intent.payment_failed', 'payment_intent.canceled' => ProviderEventKind::PaymentFailed,
            'charge.refunded', 'refund.updated', 'charge.refund.updated' => ProviderEventKind::RefundSucceeded,
            default => ProviderEventKind::Unknown,
        };
    }

    /**
     * The identifier the platform's own ledger is keyed on.
     *
     * Refund events describe a charge or a refund, but the transaction row
     * they belong to was created from the payment intent, so the intent id is
     * what we resolve back to.
     *
     * @param  array<string, mixed>  $object
     */
    private static function referenceFor(ProviderEventKind $kind, array $object): ?string
    {
        $intent = $object['payment_intent'] ?? null;

        if ($kind === ProviderEventKind::RefundSucceeded && is_string($intent) && $intent !== '') {
            return $intent;
        }

        $id = $object['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private static function amountMinorFor(ProviderEventKind $kind, array $object): ?int
    {
        $candidates = $kind === ProviderEventKind::RefundSucceeded
            ? ['amount_refunded', 'amount']
            : ['amount_received', 'amount'];

        foreach ($candidates as $key) {
            if (isset($object[$key]) && is_numeric($object[$key]) && (int) $object[$key] > 0) {
                return (int) $object[$key];
            }
        }

        return isset($object['amount']) && is_numeric($object['amount']) ? (int) $object['amount'] : null;
    }

    /**
     * The failure code or message on a payment intent.
     *
     * A decline code is preferred over the error code because it is the
     * issuer's own reason, and dunning behaves differently for "insufficient
     * funds" than for a generic decline.
     *
     * @param  array<string, mixed>  $object
     */
    private static function errorField(array $object, string $field): ?string
    {
        $error = $object['last_payment_error'] ?? null;

        if (! is_array($error)) {
            // On a charge the failure is flat rather than nested.
            return $field === 'code'
                ? self::stringOrNull($object['failure_code'] ?? null)
                : self::stringOrNull($object['failure_message'] ?? null);
        }

        return $field === 'code'
            ? self::stringOrNull($error['decline_code'] ?? null) ?? self::stringOrNull($error['code'] ?? null)
            : self::stringOrNull($error['message'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private static function chargeReference(array $intent): ?string
    {
        $charge = $intent['latest_charge'] ?? null;

        // Expanded, it is the whole charge object; unexpanded, just its id.
        return is_array($charge)
            ? self::stringOrNull($charge['id'] ?? null)
            : self::stringOrNull($charge);
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private static function nextActionUrl(array $intent): ?string
    {
        $nextAction = $intent['next_action'] ?? null;
        $redirect = is_array($nextAction) ? ($nextAction['redirect_to_url'] ?? null) : null;

        return is_array($redirect) ? self::stringOrNull($redirect['url'] ?? null) : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Header lookup is case-insensitive because header casing depends on the
     * web server in front of the application, and a signature that fails to be
     * found is indistinguishable from a signature that fails to match.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) !== $name) {
                continue;
            }

            $first = is_array($value) ? ($value[0] ?? null) : $value;

            return is_string($first) && $first !== '' ? $first : null;
        }

        return null;
    }
}
