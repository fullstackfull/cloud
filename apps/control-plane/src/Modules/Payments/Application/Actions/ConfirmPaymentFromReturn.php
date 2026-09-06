<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\Actions;

use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\DTOs\RemotePaymentState;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RemotePaymentStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\PaymentAttributionMismatchException;
use Lynomia\Modules\Payments\Domain\Exceptions\UnattributablePaymentException;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;

/**
 * Settles a payment the customer's browser has just come back from.
 *
 * The redirect itself proves nothing. Its query string is under the customer's
 * control, so "?status=succeeded" is a claim, not evidence, and a checkout
 * flow that provisions on it can be paid with a bookmark. The only inputs
 * taken from the redirect are the provider name and the payment reference —
 * enough to ask the provider a question, never enough to answer it.
 *
 * What settles the payment is the provider's own answer to retrievePayment(),
 * which is why this action exists as a sibling of webhook ingestion rather
 * than as a shortcut around it. The two paths converge on the same
 * RecordPaymentCapture, and the (provider, provider_reference) unique index
 * means whichever arrives second is a no-op.
 *
 * Asking the provider *whether* the payment succeeded is only half the check.
 * A payment reference is a bearer-shaped string that travels through browser
 * history, shared links and support tickets, so the provider is also asked
 * *whose* payment it is: the customer id in the metadata we attached at intent
 * creation is the payer of record, and the signed-in customer is compared
 * against it rather than substituted for it. Trusting the session here would
 * mean anyone holding a reference could have someone else's real capture
 * recorded against their own account.
 */
final readonly class ConfirmPaymentFromReturn
{
    public function __construct(
        private PaymentProviderRegistry $registry,
        private RecordPaymentCapture $recordCapture,
        private RecordPaymentFailure $recordFailure,
    ) {}

    /**
     * @param  string  $customerId  The authenticated customer, taken from the session rather than the
     *                              redirect. It is checked against the payer the provider names, never
     *                              used in place of it.
     * @return Transaction|null null while the payment is still in progress at the provider
     *
     * @throws UnattributablePaymentException
     * @throws PaymentAttributionMismatchException
     */
    public function execute(
        string $providerName,
        string $reference,
        string $customerId,
        ?string $invoiceId = null,
    ): ?Transaction {
        $provider = $this->registry->get($providerName);
        $state = $provider->retrievePayment($reference);
        $attribution = self::attributionOf($state);

        $event = new ProviderEvent(
            providerEventId: 'retrieve:'.$state->reference,
            type: 'payment.retrieved',
            kind: match ($state->status) {
                RemotePaymentStatus::Succeeded => ProviderEventKind::PaymentSucceeded,
                RemotePaymentStatus::Failed, RemotePaymentStatus::Cancelled => ProviderEventKind::PaymentFailed,
                default => ProviderEventKind::Unknown,
            },
            amount: $state->amount,
            currency: $state->amount->currency(),
            providerReference: $state->reference,
            payload: ['data' => $state->metadata],
            metadata: $attribution,
            failureCode: $state->failureCode,
            failureMessage: $state->failureMessage,
        );

        // Evaluated lazily inside the arms: a payment the provider has not
        // finished with is not settled either way, so there is nothing to
        // attribute and nothing to refuse.
        return match ($event->kind) {
            ProviderEventKind::PaymentSucceeded => $this->recordCapture->execute(
                $provider->name(),
                $event,
                $this->payerOf($provider->name(), $event, $customerId),
                $event->invoiceReference() ?? $invoiceId,
            ),
            ProviderEventKind::PaymentFailed => $this->recordFailure->execute(
                $provider->name(),
                $event,
                $this->payerOf($provider->name(), $event, $customerId),
                $event->invoiceReference() ?? $invoiceId,
            ),
            // Still processing: nothing is recorded, and the webhook will
            // settle it when the provider is ready.
            ProviderEventKind::Unknown, ProviderEventKind::RefundSucceeded => null,
        };
    }

    /**
     * The customer this payment may be recorded against.
     *
     * A payment the provider cannot attribute is refused for the same reason
     * webhook ingestion refuses one: guessing an owner credits an account that
     * did not pay. A payment attributed to somebody else is refused outright.
     */
    private function payerOf(string $provider, ProviderEvent $event, string $claimedCustomerId): string
    {
        $payer = $event->customerReference();

        if ($payer === null || $payer === '') {
            throw UnattributablePaymentException::forReference($provider, (string) $event->providerReference);
        }

        if ($payer !== $claimedCustomerId) {
            throw PaymentAttributionMismatchException::between(
                $provider,
                (string) $event->providerReference,
                $claimedCustomerId,
                $payer,
            );
        }

        return $payer;
    }

    /**
     * The metadata we set at intent creation, as the provider echoes it back.
     *
     * Adapters return the provider's whole (redacted) view of the payment, in
     * which our own key/value metadata is nested under "metadata" — the same
     * shape a webhook carries, so both paths read attribution identically.
     *
     * @return array<string, string>
     */
    private static function attributionOf(RemotePaymentState $state): array
    {
        $metadata = $state->metadata['metadata'] ?? null;

        if (! is_array($metadata)) {
            return [];
        }

        $attribution = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $attribution[$key] = $value;
            }
        }

        return $attribution;
    }
}
