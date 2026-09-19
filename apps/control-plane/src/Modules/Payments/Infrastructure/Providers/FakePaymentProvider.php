<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Infrastructure\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lynomia\Modules\Payments\Domain\Contracts\PaymentProvider;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentRequest;
use Lynomia\Modules\Payments\Domain\DTOs\PaymentIntentResult;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\DTOs\RemotePaymentState;
use Lynomia\Modules\Payments\Domain\DTOs\RemoteRefundResult;
use Lynomia\Modules\Payments\Domain\DTOs\SignedWebhookPayload;
use Lynomia\Modules\Payments\Domain\DTOs\WebhookVerification;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RefundStatus;
use Lynomia\Modules\Payments\Domain\Enums\RemotePaymentStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\PaymentProviderException;
use Lynomia\Modules\Payments\Domain\Exceptions\UnsupportedCurrencyException;
use Lynomia\Modules\Payments\Domain\Services\FakeProviderGuard;
use Lynomia\Modules\Shared\Domain\ValueObjects\Money;

/**
 * A payment provider that takes no money and reaches no network.
 *
 * Its behaviour is a pure function of the request, which is the property that
 * makes the failure paths testable: the last two minor units of the amount
 * select the outcome, so a test asks for 15.001 KWD to get a declined card and
 * 15.002 KWD to get insufficient funds, with no fixtures and no HTTP stub.
 *
 * Two decisions are worth stating because they look like over-engineering
 * until the alternative bites:
 *
 *  - the outcome and amount are encoded into the returned reference rather
 *    than held in a property, so retrievePayment() still answers correctly in
 *    a queue worker that never saw the original request;
 *  - webhooks are genuinely signed, with the same HMAC scheme and the same
 *    timestamp tolerance a real provider uses. A fake that let unsigned
 *    payloads through would mean the signature check — the single most
 *    security-critical branch in the module — was never executed by the test
 *    suite that is supposed to cover it.
 */
final class FakePaymentProvider implements PaymentProvider
{
    public const string NAME = 'fake';

    /** Signature header the fake reads, mirroring Stripe-Signature. */
    public const string SIGNATURE_HEADER = 'x-fake-signature';

    private const string REFERENCE_PREFIX = 'fake_pi_';

    private const string REFUND_PREFIX = 'fake_re_';

    /** Seconds a signature stays acceptable, mirroring Stripe's own default. */
    private const int DEFAULT_WEBHOOK_TOLERANCE = 300;

    /**
     * Marks the segment of a reference that carries the customer the intent
     * was created for. "cust" cannot occur in the hex digest that precedes it,
     * so the segment is unambiguous even though references vary in length.
     */
    private const string CUSTOMER_SEGMENT_PREFIX = 'cust';

    /**
     * Amount suffixes that select a declined outcome, keyed by the last two
     * minor units of the requested amount.
     *
     * @var array<int, string>
     */
    private const array DECLINE_CODES = [
        1 => 'card_declined',
        2 => 'insufficient_funds',
        3 => 'expired_card',
        4 => 'processing_error',
    ];

    /** Ask the browser to visit the provider's own page. */
    public const string NEXT_ACTION_REDIRECT = 'redirect';

    /** Keep the customer here and confirm with a client credential. */
    public const string NEXT_ACTION_CLIENT_CONFIRMATION = 'client_confirmation';

    /** @var list<string> */
    private array $supportedCurrencies;

    private string $nextAction;

    /**
     * Where decisions taken by the controlled gateway are recorded, or null
     * when this fake is a pure function of the reference.
     */
    private ?string $statePath;

    private string $webhookSecret;

    private int $webhookTolerance;

    public function __construct()
    {
        // Constructed, not resolved, is the moment worth guarding: a binding
        // overridden at runtime or a leftover test double never passes through
        // config, but it cannot avoid this constructor.
        FakeProviderGuard::assertNotProduction(self::NAME);

        /** @var list<string> $currencies */
        $currencies = config('payments.fake.currencies', ['KWD', 'USD', 'EUR', 'GBP', 'SAR', 'AED']);
        $this->supportedCurrencies = array_map(strtoupper(...), $currencies);

        $this->webhookSecret = (string) config('payments.fake.webhook_secret', 'fake_webhook_secret');

        // Clamped rather than trusted: a tolerance of zero disables the replay
        // window entirely, so a config value of 0 — which is what an unset or
        // non-numeric environment variable casts to — must not be able to turn
        // the check off silently.
        $tolerance = (int) config('payments.fake.webhook_tolerance', self::DEFAULT_WEBHOOK_TOLERANCE);
        $this->webhookTolerance = $tolerance > 0 ? $tolerance : self::DEFAULT_WEBHOOK_TOLERANCE;

        /*
         * Which shape of confirmation this fake asks a browser for. Both are
         * real flows a real gateway uses; the fake can be pointed at either so
         * that both branches of the portal's next_action handling are
         * exercised by something rather than reasoned about.
         */
        $requested = (string) config('payments.fake.next_action', 'redirect');
        $this->nextAction = $requested === self::NEXT_ACTION_CLIENT_CONFIRMATION
            ? self::NEXT_ACTION_CLIENT_CONFIRMATION
            : self::NEXT_ACTION_REDIRECT;

        $statePath = config('payments.fake.state_path');
        $this->statePath = is_string($statePath) && trim($statePath) !== '' ? $statePath : null;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->supportedCurrencies, true);
    }

    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        $this->assertSupportedCurrency($request->amount->currency());

        $failureCode = self::declineCodeFor($request->amount);
        $status = $failureCode !== null
            ? RemotePaymentStatus::Failed
            : ($request->confirm ? RemotePaymentStatus::Succeeded : RemotePaymentStatus::RequiresAction);

        $reference = $this->buildReference(
            $request->amount,
            $status,
            $request->idempotencyKey,
            $request->metadata['customer_id'] ?? null,
        );

        return new PaymentIntentResult(
            reference: $reference,
            status: $status,
            amount: $request->amount,
            // Shaped like a real one so that any code which accidentally logs
            // or persists it is caught by the same redaction rules.
            clientSecret: $this->clientSecretFor($reference, $request->idempotencyKey),
            nextActionUrl: $status === RemotePaymentStatus::RequiresAction
                && $this->nextAction === self::NEXT_ACTION_REDIRECT
                ? $this->authorisationUrlFor($reference)
                : null,
            failureCode: $failureCode,
            failureMessage: $failureCode !== null ? 'The fake provider declined this amount by design.' : null,
            metadata: ['fake' => true, 'metadata' => $request->metadata],
        );
    }

    public function retrievePayment(string $reference): RemotePaymentState
    {
        [$status, $amount, $customerId] = $this->decodeReference($reference);

        /*
         * A decision the controlled gateway recorded outranks the one encoded
         * in the reference.
         *
         * The reference is immutable by design — it is how a queue worker that
         * never saw the request still knows the outcome — which means an
         * intent created as "requires action" can never become "succeeded" on
         * its own. That is correct for the unit tests and useless for a
         * browser journey, where somebody has to be able to authorise the
         * payment. So an approval or a decline is written to a file, and this
         * is where it is read back. With no file configured nothing changes.
         */
        $recorded = $this->recordedStatusFor($reference);

        if ($recorded !== null) {
            $status = $recorded['status'];
        }

        $failureCode = $status === RemotePaymentStatus::Failed
            ? ($recorded['failure_code'] ?? self::declineCodeFor($amount) ?? 'card_declined')
            : null;

        return new RemotePaymentState(
            reference: $reference,
            status: $status,
            amount: $amount,
            chargeReference: $status === RemotePaymentStatus::Succeeded
                ? str_replace(self::REFERENCE_PREFIX, 'fake_ch_', $reference)
                : null,
            failureCode: $failureCode,
            failureMessage: $failureCode !== null ? 'The fake provider declined this amount by design.' : null,
            // Shaped like Stripe's: the provider's own view of the payment,
            // with the attribution metadata we attached at creation nested
            // under "metadata". A caller confirming a browser return has to be
            // able to ask the provider who the payer is, rather than being
            // told by the request it is validating.
            metadata: [
                'fake' => true,
                'metadata' => $customerId === null ? [] : ['customer_id' => $customerId],
            ],
        );
    }

    public function refund(string $chargeReference, Money $amount, string $reason, string $idempotencyKey): RemoteRefundResult
    {
        $this->assertSupportedCurrency($amount->currency());

        $failure = self::declineCodeFor($amount);

        return new RemoteRefundResult(
            // Derived from the idempotency key rather than from the amount, so
            // that two deliberate refunds of the same amount are two refunds
            // here as well. A reference keyed on the parameters would hide the
            // very collision a real provider would silently make.
            reference: self::REFUND_PREFIX.substr(hash('sha256', $chargeReference.'|'.$idempotencyKey), 0, 24),
            status: $failure !== null ? RefundStatus::Failed : RefundStatus::Succeeded,
            amount: $amount,
            failureReason: $failure,
            metadata: ['fake' => true, 'charge' => $chargeReference, 'reason' => $reason],
        );
    }

    public function verifyWebhookSignature(string $rawPayload, array $headers): WebhookVerification
    {
        $header = self::header($headers, self::SIGNATURE_HEADER);

        if ($header === null) {
            return WebhookVerification::failed('no signature header was presented');
        }

        [$timestamp, $signature] = self::parseSignatureHeader($header);

        if ($timestamp === null || $signature === null) {
            return WebhookVerification::failed('the signature header could not be parsed');
        }

        $expected = self::sign($timestamp, $rawPayload, $this->webhookSecret);

        // Constant time: a fast reject on the first differing byte turns the
        // HMAC into a byte-at-a-time oracle.
        if (! hash_equals($expected, $signature)) {
            return WebhookVerification::failed('the signature does not match the payload');
        }

        /*
         * The timestamp is checked after the signature, and it matters that it
         * is inside the signed material: an attacker who could edit the
         * timestamp freely would be able to replay a captured request forever.
         */
        if (abs(time() - $timestamp) > $this->webhookTolerance) {
            return WebhookVerification::failed('the signature timestamp is outside the tolerance window');
        }

        return WebhookVerification::verified('fake.v1');
    }

    public function parseWebhookEvent(array $payload): ?ProviderEvent
    {
        $id = $payload['id'] ?? null;
        $type = $payload['type'] ?? null;

        // Without an id there is no replay key, so there is nothing we can
        // safely record or deduplicate. Reject rather than invent one.
        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '') {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $currency = is_string($data['currency'] ?? null) ? strtoupper((string) $data['currency']) : null;
        $minor = isset($data['amount_minor']) && is_numeric($data['amount_minor'])
            ? (int) $data['amount_minor']
            : null;

        /** @var array<string, string> $metadata */
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        return new ProviderEvent(
            providerEventId: $id,
            type: $type,
            kind: self::kindFor($type),
            amount: $currency !== null && $minor !== null ? Money::ofMinor($minor, $currency) : null,
            currency: $currency,
            providerReference: is_string($data['reference'] ?? null) ? $data['reference'] : null,
            payload: $payload,
            metadata: $metadata,
            failureCode: is_string($data['failure_code'] ?? null) ? $data['failure_code'] : null,
            failureMessage: is_string($data['failure_message'] ?? null) ? $data['failure_message'] : null,
            occurredAt: isset($payload['created']) && is_numeric($payload['created'])
                ? CarbonImmutable::createFromTimestampUTC((int) $payload['created'])
                : null,
        );
    }

    /**
     * Build a webhook exactly as the provider would send it, signature and all.
     *
     * @param  array<string, string>  $metadata
     * @param  int|null  $timestamp  Overridable so a test can produce a stale but otherwise valid
     *                               signature and prove the tolerance window is enforced.
     */
    public function emitWebhook(
        ProviderEventKind $kind,
        string $reference,
        Money $amount,
        array $metadata = [],
        ?string $eventId = null,
        ?int $timestamp = null,
        ?string $failureCode = null,
    ): SignedWebhookPayload {
        $payload = [
            'id' => $eventId ?? 'evt_fake_'.Str::lower((string) Str::ulid()),
            'type' => self::typeFor($kind),
            'created' => $timestamp ?? time(),
            'data' => [
                'reference' => $reference,
                'amount_minor' => $amount->minorUnits(),
                'currency' => $amount->currency(),
                'metadata' => $metadata,
                'failure_code' => $failureCode,
                'failure_message' => $failureCode !== null
                    ? 'The fake provider declined this amount by design.'
                    : null,
            ],
        ];

        return $this->signPayload(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $timestamp,
        );
    }

    /**
     * Sign an arbitrary body, so a test can sign one payload and deliver
     * another and watch the check fail.
     */
    public function signPayload(string $rawPayload, ?int $timestamp = null): SignedWebhookPayload
    {
        $timestamp ??= time();

        return new SignedWebhookPayload(
            rawPayload: $rawPayload,
            headers: [
                self::SIGNATURE_HEADER => sprintf(
                    't=%d,v1=%s',
                    $timestamp,
                    self::sign($timestamp, $rawPayload, $this->webhookSecret),
                ),
            ],
        );
    }

    /**
     * The amount suffix that makes this provider decline, exposed so tests
     * state their intent instead of hard-coding a magic number.
     */
    public static function declineAmount(Money $base, string $code = 'card_declined'): Money
    {
        $suffix = array_search($code, self::DECLINE_CODES, true);

        if (! is_int($suffix)) {
            throw new InvalidArgumentException(sprintf(
                'The fake provider has no decline code "%s"; it knows %s.',
                $code,
                implode(', ', self::DECLINE_CODES),
            ));
        }

        $minor = $base->minorUnits();

        return Money::ofMinor($minor - ($minor % 100) + $suffix, $base->currency());
    }

    public static function declineCodeFor(Money $amount): ?string
    {
        return self::DECLINE_CODES[$amount->minorUnits() % 100] ?? null;
    }

    /**
     * The page a redirecting browser is sent to.
     *
     * It points at the portal, because that is where the controlled gateway
     * screen lives: a JSON API cannot serve the provider's hosted page, and a
     * URL nobody can open would make the redirect branch unprovable.
     */
    public function authorisationUrlFor(string $reference): string
    {
        $configured = config('payments.fake.authorise_url');

        $base = is_string($configured) && trim($configured) !== ''
            ? rtrim($configured, '/')
            : rtrim((string) config('app.frontend_url'), '/').'/fake-gateway/authorise';

        return $base.'/'.$reference;
    }

    /**
     * Which confirmation shape this fake is configured to ask for.
     */
    public function nextActionShape(): string
    {
        return $this->nextAction;
    }

    /**
     * Records the decision a person took on the controlled gateway page.
     *
     * This is the fake's only mutable state, it exists only when a state file
     * is configured, and it is what makes an authorisation in a browser
     * visible to a later server-side retrieve. It does not settle anything: an
     * invoice is settled when the platform receives the signed webhook, which
     * the gateway sends next.
     */
    public function record(string $reference, RemotePaymentStatus $status, ?string $failureCode = null): void
    {
        if ($this->statePath === null) {
            throw PaymentProviderException::requestFailed(
                self::NAME,
                'record_decision',
                ['error_code' => 'no_state_path'],
            );
        }

        $state = $this->readState();
        $state[$reference] = ['status' => $status->value, 'failure_code' => $failureCode];

        $directory = dirname($this->statePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        file_put_contents(
            $this->statePath,
            json_encode($state, JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }

    /**
     * The client credential this fake would have issued for an intent.
     *
     * The controlled gateway's client-confirmation endpoint compares what the
     * browser presents against this, so that path proves something: a browser
     * that does not hold the credential cannot confirm the payment. It is
     * derived rather than stored, exactly as the intent's own is.
     */
    public function clientSecretFor(string $reference, string $idempotencyKey): string
    {
        return $reference.'_secret_'.substr(hash('sha256', $idempotencyKey), 0, 16);
    }

    /**
     * @return array{status: RemotePaymentStatus, failure_code: string|null}|null
     */
    private function recordedStatusFor(string $reference): ?array
    {
        if ($this->statePath === null) {
            return null;
        }

        $entry = $this->readState()[$reference] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $status = RemotePaymentStatus::tryFrom((string) ($entry['status'] ?? ''));

        if ($status === null) {
            return null;
        }

        $failureCode = $entry['failure_code'] ?? null;

        return [
            'status' => $status,
            'failure_code' => is_string($failureCode) && $failureCode !== '' ? $failureCode : null,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readState(): array
    {
        if ($this->statePath === null || ! is_file($this->statePath)) {
            return [];
        }

        $raw = (string) file_get_contents($this->statePath);

        if (trim($raw) === '') {
            return [];
        }

        try {
            /** @var array<string, array<string, mixed>> $decoded */
            $decoded = (array) json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A half-written file is not a payment decision. Treat it as no
            // decision rather than as a failed payment.
            return [];
        }

        return $decoded;
    }

    private function assertSupportedCurrency(string $currency): void
    {
        if (! $this->supportsCurrency($currency)) {
            throw UnsupportedCurrencyException::forProvider(self::NAME, $currency);
        }
    }

    /**
     * The reference carries its own outcome and amount.
     *
     * A queue worker retrieving a payment has only this string; encoding the
     * facts into it keeps the fake correct across process boundaries without
     * a store that production would not have.
     */
    private function buildReference(
        Money $amount,
        RemotePaymentStatus $status,
        string $idempotencyKey,
        ?string $customerId,
    ): string {
        return sprintf(
            '%s%s_%s_%d_%s%s',
            self::REFERENCE_PREFIX,
            $status->value,
            $amount->currency(),
            $amount->minorUnits(),
            substr(hash('sha256', $idempotencyKey), 0, 8),
            $customerId === null ? '' : '_'.self::CUSTOMER_SEGMENT_PREFIX.$customerId,
        );
    }

    /**
     * @return array{0: RemotePaymentStatus, 1: Money, 2: string|null}
     */
    private function decodeReference(string $reference): array
    {
        $body = str_starts_with($reference, self::REFERENCE_PREFIX)
            ? substr($reference, strlen(self::REFERENCE_PREFIX))
            : null;

        $parts = $body === null ? [] : explode('_', $body);

        $customerId = null;

        // Optional, because a reference may predate the customer segment or be
        // built by hand in a fixture; absent is "the provider knows of no
        // payer", which the caller must then refuse rather than fill in.
        if (count($parts) > 4 && str_starts_with((string) end($parts), self::CUSTOMER_SEGMENT_PREFIX)) {
            $customerId = substr((string) array_pop($parts), strlen(self::CUSTOMER_SEGMENT_PREFIX)) ?: null;
        }

        if (count($parts) < 4) {
            throw PaymentProviderException::requestFailed(
                self::NAME,
                'retrieve_payment',
                ['provider_reference' => $reference, 'error_code' => 'resource_missing'],
            );
        }

        $hash = array_pop($parts);
        $minor = array_pop($parts);
        $currency = array_pop($parts);
        $status = RemotePaymentStatus::tryFrom(implode('_', $parts));

        if ($status === null || ! is_numeric($minor) || $hash === '') {
            throw PaymentProviderException::requestFailed(
                self::NAME,
                'retrieve_payment',
                ['provider_reference' => $reference, 'error_code' => 'resource_missing'],
            );
        }

        return [$status, Money::ofMinor((int) $minor, (string) $currency), $customerId];
    }

    private static function kindFor(string $type): ProviderEventKind
    {
        return match ($type) {
            'payment.succeeded' => ProviderEventKind::PaymentSucceeded,
            'payment.failed' => ProviderEventKind::PaymentFailed,
            'refund.succeeded' => ProviderEventKind::RefundSucceeded,
            default => ProviderEventKind::Unknown,
        };
    }

    private static function typeFor(ProviderEventKind $kind): string
    {
        return match ($kind) {
            ProviderEventKind::PaymentSucceeded => 'payment.succeeded',
            ProviderEventKind::PaymentFailed => 'payment.failed',
            ProviderEventKind::RefundSucceeded => 'refund.succeeded',
            ProviderEventKind::Unknown => 'diagnostic.ping',
        };
    }

    private static function sign(int $timestamp, string $rawPayload, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawPayload, $secret);
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private static function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            match ($pair[0]) {
                't' => $timestamp = is_numeric($pair[1]) ? (int) $pair[1] : null,
                'v1' => $signature = $pair[1],
                default => null,
            };
        }

        return [$timestamp, $signature];
    }

    /**
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
