<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Billing\Domain\Enums\TransactionStatus;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\Enums\TransactionKind;
use Lynomia\Modules\Payments\Domain\Events\PaymentCaptured;
use Lynomia\Modules\Payments\Domain\Exceptions\MalformedWebhookPayloadException;
use Lynomia\Modules\Payments\Domain\Exceptions\UnattributablePaymentException;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;

/**
 * Writes a verified capture into the ledger, exactly once.
 *
 * Idempotency rests on the (provider, provider_reference) unique index rather
 * than on a check-then-insert, because a check-then-insert is only atomic
 * under a lock that does not exist until the row does. Two workers handling
 * the same redelivered event both find nothing, both insert, and the database
 * rejects one of them — which is the intended outcome, so that rejection is
 * caught and turned into "read the row the other worker wrote".
 *
 * The action deliberately stops at the ledger. It does not mark an invoice
 * paid, close an order or trigger provisioning: those are billing decisions
 * with their own rules about partial payments, overpayments and credit notes,
 * and burying them here would make "record that money arrived" impossible to
 * reuse. It emits PaymentCaptured and lets the wiring decide.
 */
final readonly class RecordPaymentCapture
{
    /**
     * @param  string|null  $customerId  Overrides the customer recorded in the payment's metadata, for the
     *                                   server-side retrieve path where the caller already knows the payer.
     *
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
                'a capture event arrived with no payment reference, so it cannot be recorded idempotently',
            );
        }

        if ($event->amount === null) {
            throw MalformedWebhookPayloadException::forProvider(
                $provider,
                sprintf('the capture event for %s carries no amount', $reference),
            );
        }

        $customerId ??= $event->customerReference();

        if ($customerId === null || $customerId === '') {
            throw UnattributablePaymentException::forReference($provider, $reference);
        }

        $invoiceId ??= $event->invoiceReference();

        try {
            [$transaction, $captured] = $this->settle($provider, $event, $reference, $customerId, $invoiceId);
        } catch (UniqueConstraintViolationException) {
            /*
             * A concurrent worker inserted the row between our locked read and
             * our insert. Its transaction has committed by the time the
             * constraint fired, so the second pass finds the row and settles
             * on it instead of creating a second capture.
             */
            [$transaction, $captured] = $this->settle($provider, $event, $reference, $customerId, $invoiceId);
        }

        if ($captured) {
            $captureRecorded = new PaymentCaptured(
                transactionId: $transaction->id,
                customerId: $transaction->customer_id,
                invoiceId: $transaction->invoice_id,
                provider: $transaction->provider,
                providerReference: $reference,
                amount: $transaction->amount(),
                capturedAt: $transaction->processed_at ?? now()->toImmutable(),
            );

            /*
             * Held until the outermost transaction commits, not merely until
             * the inner one above closes. This action is normally called from
             * inside IngestWebhookEvent's own transaction, so dispatching at
             * that level pushes a job onto a queue that shares no transaction
             * with the database: a worker can dequeue it and find the capture
             * row not yet visible. The listener that settles the invoice
             * treats a capture it cannot find as data loss, so the charge
             * would stand with nothing settled and nothing retrying.
             *
             * The level is checked rather than left to afterCommit's own
             * fallback because the transaction manager's pending list spans
             * connections: with nothing open here the announcement belongs
             * now, not behind some other connection's commit.
             */
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(static function () use ($captureRecorded): void {
                    event($captureRecorded);
                });
            } else {
                event($captureRecorded);
            }
        }

        return $transaction;
    }

    /**
     * @return array{0: Transaction, 1: bool} the transaction, and whether this call is the one that settled it
     */
    private function settle(
        string $provider,
        ProviderEvent $event,
        string $reference,
        string $customerId,
        ?string $invoiceId,
    ): array {
        return DB::transaction(function () use ($provider, $event, $reference, $customerId, $invoiceId): array {
            /** @var Transaction|null $existing */
            $existing = Transaction::query()
                ->where('provider', $provider)
                ->where('provider_reference', $reference)
                ->lockForUpdate()
                ->first();

            if ($existing?->status === TransactionStatus::Succeeded) {
                // Already captured. Returning it unchanged is the whole point
                // of the replay defence.
                return [$existing, false];
            }

            $attributes = [
                'customer_id' => $customerId,
                'invoice_id' => $invoiceId,
                'provider' => $provider,
                'provider_reference' => $reference,
                'kind' => TransactionKind::Charge,
                'status' => TransactionStatus::Succeeded,
                'amount_minor' => $event->amount?->minorUnits(),
                'currency' => $event->amount?->currency(),
                'failure_code' => null,
                'failure_message' => null,
                'provider_metadata' => $this->metadataFor($event),
                'processed_at' => $event->occurredAt ?? now(),
            ];

            if ($existing !== null) {
                // A pending or failed attempt that the provider has now
                // settled: the same payment, so the same row.
                $existing->fill($attributes)->save();

                return [$existing, true];
            }

            return [Transaction::create($attributes), true];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataFor(ProviderEvent $event): array
    {
        return [
            'event_id' => $event->providerEventId,
            'event_type' => $event->type,
            // Secrets are stripped by the model's mutator, so the raw object
            // can be kept for operators without auditing every provider field.
            'object' => $event->payload['data'] ?? $event->payload,
        ];
    }
}
