<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Domain\Events\PaymentFailed;
use Lynomia\Modules\Payments\Domain\Exceptions\MalformedWebhookPayloadException;
use Lynomia\Modules\Payments\Domain\Exceptions\UnattributablePaymentException;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;

/**
 * Records a declined or errored payment.
 *
 * A failure gets a transaction row of its own rather than being logged and
 * forgotten: dunning needs to count how many times a customer's card has been
 * refused and with which code, and support needs to answer "why did my payment
 * not go through" without reading application logs.
 *
 * The row is created with status Failed. It shares the same
 * (provider, provider_reference) key as the eventual capture, so if the
 * customer completes the same intent after a 3-D Secure challenge,
 * RecordPaymentCapture settles this very row instead of creating a second one.
 */
final readonly class RecordPaymentFailure
{
    /**
     * @throws MalformedWebhookPayloadException
     * @throws UnattributablePaymentException
     */
    public function execute(
        string $provider,
        ProviderEvent $event,
        ?string $customerId = null,
        ?string $invoiceId = null,
    ): Transaction {
        $reference = $event->providerReference;

        if ($reference === null || $reference === '') {
            throw MalformedWebhookPayloadException::forProvider(
                $provider,
                'a failure event arrived with no payment reference, so it cannot be recorded idempotently',
            );
        }

        $customerId ??= $event->customerReference();

        if ($customerId === null || $customerId === '') {
            throw UnattributablePaymentException::forReference($provider, $reference);
        }

        $invoiceId ??= $event->invoiceReference();

        try {
            $transaction = $this->record($provider, $event, $reference, $customerId, $invoiceId);
        } catch (UniqueConstraintViolationException) {
            $transaction = $this->record($provider, $event, $reference, $customerId, $invoiceId);
        }

        event(new PaymentFailed(
            transactionId: $transaction->id,
            customerId: $transaction->customer_id,
            invoiceId: $transaction->invoice_id,
            provider: $transaction->provider,
            providerReference: $reference,
            amount: $transaction->amount(),
            failureCode: $transaction->failure_code,
            failureMessage: $transaction->failure_message,
            failedAt: $transaction->processed_at ?? now()->toImmutable(),
        ));

        return $transaction;
    }

    private function record(
        string $provider,
        ProviderEvent $event,
        string $reference,
        string $customerId,
        ?string $invoiceId,
    ): Transaction {
        return DB::transaction(function () use ($provider, $event, $reference, $customerId, $invoiceId): Transaction {
            /** @var Transaction|null $existing */
            $existing = Transaction::query()
                ->where('provider', $provider)
                ->where('provider_reference', $reference)
                ->lockForUpdate()
                ->first();

            /*
             * A capture that has already settled outranks a late failure
             * event. Providers do send them out of order, and downgrading a
             * successful charge to failed would un-pay a paid invoice.
             */
            if ($existing?->status === TransactionStatus::Succeeded) {
                return $existing;
            }

            $attributes = [
                'customer_id' => $customerId,
                'invoice_id' => $invoiceId,
                'provider' => $provider,
                'provider_reference' => $reference,
                'kind' => TransactionKind::Charge,
                'status' => TransactionStatus::Failed,
                'amount_minor' => $event->amount?->minorUnits() ?? 0,
                'currency' => $event->amount?->currency() ?? $event->currency ?? config('billing.default_currency'),
                // Truncated to the column width so a verbose provider message
                // cannot fail the write that records why the payment failed.
                'failure_code' => $event->failureCode === null ? null : mb_substr($event->failureCode, 0, 64),
                'failure_message' => $event->failureMessage === null ? null : mb_substr($event->failureMessage, 0, 255),
                'provider_metadata' => [
                    'event_id' => $event->providerEventId,
                    'event_type' => $event->type,
                    'object' => $event->payload['data'] ?? $event->payload,
                ],
                'processed_at' => $event->occurredAt ?? now(),
            ];

            if ($existing !== null) {
                $existing->fill($attributes)->save();

                return $existing;
            }

            return Transaction::create($attributes);
        });
    }
}
