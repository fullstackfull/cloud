<?php

declare(strict_types=1);

namespace Lynomia\Modules\Payments\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Lynomia\Modules\Payments\Application\DTOs\WebhookIngestionResult;
use Lynomia\Modules\Payments\Domain\Contracts\PaymentProvider;
use Lynomia\Modules\Payments\Domain\DTOs\ProviderEvent;
use Lynomia\Modules\Payments\Domain\Enums\ProviderEventKind;
use Lynomia\Modules\Payments\Domain\Enums\WebhookEventStatus;
use Lynomia\Modules\Payments\Domain\Exceptions\MalformedWebhookPayloadException;
use Lynomia\Modules\Payments\Domain\Exceptions\WebhookSignatureException;
use Lynomia\Modules\Payments\Infrastructure\Models\Transaction;
use Lynomia\Modules\Payments\Infrastructure\Models\WebhookEvent;
use Lynomia\Modules\Payments\Infrastructure\PaymentProviderRegistry;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * The only door through which provider events enter the platform.
 *
 * The order of the four steps below is the security design, and each step is
 * ordered the way it is because the obvious alternative has a known failure:
 *
 *  1. Verify the signature against the raw bytes. Nothing is decoded, stored
 *     or logged before this passes. A webhook endpoint is a public URL, so an
 *     unverified payload is attacker input; storing it "for debugging" is how
 *     a forged event later gets replayed by an operator draining a queue.
 *
 *  2. Parse into a ProviderEvent. The provider event id is mandatory: it is
 *     the replay key, and an event without one cannot be deduplicated and so
 *     must not be acted on.
 *
 *  3. Record the event id *before* acting on it. The unique
 *     (provider, provider_event_id) index is the replay defence. Acting first
 *     and recording after leaves a window in which a redelivery — which
 *     providers send routinely, and send twice within milliseconds when a
 *     handler is slow — captures a second time. The insert is attempted
 *     unconditionally and a unique violation is treated as "someone else got
 *     here first", because a SELECT-then-INSERT is not atomic.
 *
 *  4. Handle it while holding a row lock on the event, and stamp the outcome.
 *     Two workers racing the same event serialise on that lock; the loser
 *     wakes to find a settled row and returns its recorded outcome instead of
 *     handling it again.
 *
 * Failures record attempts and last_error and leave the event unsettled, so a
 * transient fault can be retried. The retry is safe for the same reason the
 * redelivery is: the settlement actions are themselves keyed on the provider
 * reference.
 *
 * Note what this action is not: it is not reachable from a browser redirect.
 * Nothing is ever marked paid because a customer's browser came back to a
 * success URL — that URL is under the customer's control. Provisioning follows
 * a verified webhook or an explicit server-side retrievePayment(), and nothing
 * else.
 */
final readonly class IngestWebhookEvent
{
    public function __construct(
        private PaymentProviderRegistry $registry,
        private RecordPaymentCapture $recordCapture,
        private RecordPaymentFailure $recordFailure,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @param  array<string, string|list<string>>  $headers
     *
     * @throws WebhookSignatureException
     * @throws MalformedWebhookPayloadException
     */
    public function execute(string $providerName, string $rawPayload, array $headers): WebhookIngestionResult
    {
        $provider = $this->registry->get($providerName);

        $verification = $provider->verifyWebhookSignature($rawPayload, $headers);

        if (! $verification->verified) {
            // Nothing about this payload is recorded: not the body, not the
            // claimed event id. The reason alone is enough to investigate.
            throw WebhookSignatureException::rejected(
                $provider->name(),
                $verification->reason ?? 'signature verification failed',
            );
        }

        $event = $provider->parseWebhookEvent($this->decode($provider, $rawPayload));

        if ($event === null) {
            throw MalformedWebhookPayloadException::forProvider(
                $provider->name(),
                'the payload carries no event identifier, so it cannot be recorded idempotently',
            );
        }

        $this->claim($provider, $event, $verification->verifiedBy);

        try {
            return DB::transaction(fn (): WebhookIngestionResult => $this->handleUnderLock($provider, $event));
        } catch (Throwable $e) {
            /*
             * Recorded after the rollback, on purpose: writing the error
             * inside the transaction that is about to be rolled back would
             * discard the only record of why the event failed.
             */
            $this->recordFailureOn($provider->name(), $event->providerEventId, $e);

            throw $e;
        }
    }

    /**
     * Write the event id before anything acts on it.
     *
     * insertOrIgnore rather than firstOrCreate: the check and the insert have
     * to be one statement, or two workers both pass the check.
     */
    private function claim(PaymentProvider $provider, ProviderEvent $event, ?string $verifiedBy): void
    {
        WebhookEvent::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'provider' => $provider->name(),
            'provider_event_id' => $event->providerEventId,
            'event_type' => mb_substr($event->type, 0, 128),
            'status' => WebhookEventStatus::Received->value,
            'attempts' => 0,
            // Redacted here rather than in the model, because insertOrIgnore
            // is a query-builder write that never reaches the mutator.
            'payload' => json_encode($this->redactor->redact($event->payload), JSON_THROW_ON_ERROR),
            'signature_verified_by' => $verifiedBy,
            'provider_created_at' => $event->occurredAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function handleUnderLock(PaymentProvider $provider, ProviderEvent $event): WebhookIngestionResult
    {
        /** @var WebhookEvent $record */
        $record = WebhookEvent::query()
            ->where('provider', $provider->name())
            ->where('provider_event_id', $event->providerEventId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($record->status->isSettled()) {
            // A redelivery, or the losing side of a concurrent race. Return
            // what was recorded; do not capture again.
            return WebhookIngestionResult::alreadyProcessed(
                $record,
                transaction: $this->transactionFor($provider->name(), $event),
            );
        }

        $transaction = match ($event->kind) {
            ProviderEventKind::PaymentSucceeded => $this->recordCapture->execute($provider->name(), $event),
            ProviderEventKind::PaymentFailed => $this->recordFailure->execute($provider->name(), $event),
            // A refund's own webhook confirms a refund IssueRefund already
            // recorded; there is nothing to create from it here. Recording and
            // acknowledging it stops the provider redelivering.
            ProviderEventKind::RefundSucceeded, ProviderEventKind::Unknown => null,
        };

        $record->fill([
            'status' => $event->kind->isActionable()
                ? WebhookEventStatus::Processed
                : WebhookEventStatus::Ignored,
            'attempts' => $record->attempts + 1,
            'last_error' => null,
            'processed_at' => now(),
        ])->save();

        return WebhookIngestionResult::processed($record, transaction: $transaction);
    }

    /**
     * The transaction a redelivered event refers to, so a duplicate returns
     * the same outcome the first delivery did rather than a bare "duplicate".
     */
    private function transactionFor(string $provider, ProviderEvent $event): ?Transaction
    {
        if ($event->providerReference === null) {
            return null;
        }

        return Transaction::query()
            ->where('provider', $provider)
            ->where('provider_reference', $event->providerReference)
            ->first();
    }

    private function recordFailureOn(string $provider, string $providerEventId, Throwable $e): void
    {
        try {
            WebhookEvent::query()
                ->where('provider', $provider)
                ->where('provider_event_id', $providerEventId)
                ->update([
                    'status' => WebhookEventStatus::Failed->value,
                    'attempts' => DB::raw('attempts + 1'),
                    // An exception message can quote the request that carried
                    // a credential, and last_error is read by everyone with
                    // support access.
                    'last_error' => $this->redactor->redactString($e->getMessage()),
                    'updated_at' => now(),
                ]);
        } catch (Throwable) {
            // The original failure is the one worth propagating; losing the
            // bookkeeping write must not mask it.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(PaymentProvider $provider, string $rawPayload): array
    {
        try {
            // No annotation asserting an array: a valid JSON document can be
            // a string or a number, and the check below is what actually
            // establishes this is an object.
            $decoded = json_decode($rawPayload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MalformedWebhookPayloadException::forProvider($provider->name(), 'the body is not valid JSON');
        }

        if (! is_array($decoded)) {
            throw MalformedWebhookPayloadException::forProvider($provider->name(), 'the body is not a JSON object');
        }

        return $decoded;
    }
}
