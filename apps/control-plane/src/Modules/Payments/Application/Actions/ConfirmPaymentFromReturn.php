<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\Actions;

use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\RemotePaymentStatus;
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
 * What settles the payment is the provider's own answer to
 * retrievePayment(), which is why this action exists as a sibling of webhook
 * ingestion rather than as a shortcut around it. The two paths converge on the
 * same RecordPaymentCapture, and the (provider, provider_reference) unique
 * index means whichever arrives second is a no-op.
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
     *                              redirect, so a reference guessed or copied from someone else's
     *                              checkout cannot be attributed to the guesser.
     * @return Transaction|null null while the payment is still in progress at the provider
     */
    public function execute(
        string $providerName,
        string $reference,
        string $customerId,
        ?string $invoiceId = null,
    ): ?Transaction {
        $provider = $this->registry->get($providerName);
        $state = $provider->retrievePayment($reference);

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
            metadata: [],
            failureCode: $state->failureCode,
            failureMessage: $state->failureMessage,
        );

        return match ($event->kind) {
            ProviderEventKind::PaymentSucceeded => $this->recordCapture->execute(
                $provider->name(),
                $event,
                $customerId,
                $invoiceId,
            ),
            ProviderEventKind::PaymentFailed => $this->recordFailure->execute(
                $provider->name(),
                $event,
                $customerId,
                $invoiceId,
            ),
            // Still processing: nothing is recorded, and the webhook will
            // settle it when the provider is ready.
            ProviderEventKind::Unknown, ProviderEventKind::RefundSucceeded => null,
        };
    }
}
