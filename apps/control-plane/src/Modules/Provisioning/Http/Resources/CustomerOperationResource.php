<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Lynomia\Modules\Provisioning\Domain\Enums\CustomerFailureReason;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Domain\Enums\CustomerOperationState;

/**
 * One piece of work in flight, as the customer who asked for it may watch it.
 *
 * AR-12. A 202 means the request was accepted and nothing more, and until now
 * there was no way to find out what became of it: the portal sent a reboot,
 * got a job back, and had no endpoint to read that job's state. The customer's
 * experience of every asynchronous action was "nothing happened".
 *
 * What makes this a contract rather than a status dump:
 *
 *  - `state` is the seven-word customer vocabulary, not the engine's. The
 *    engine's `queued`/`running` and the customer's `queued`/`processing`
 *    happen to be close today; the mapping exists so that they can diverge
 *    without a client learning a new word.
 *
 *  - `retry_advice` is published, so the screen never derives it. A retry
 *    control is drawn from `safe_to_retry` alone, which is what stops one
 *    appearing beside an operation whose result nobody knows.
 *
 *  - `poll_after_ms` is the server's own hint about when to look again, and it
 *    is null once the state is terminal. A client that honours it cannot poll
 *    a finished operation forever, and the platform can slow every client down
 *    from one place if it ever needs to.
 *
 * What is deliberately absent is the same list `ProvisioningEventResource`
 * refuses: `last_error`, `payload`, `result`, `provider`, `remote_job_id`, the
 * attempt counters and the retry machinery. `failure_reason` is the customer's
 * bounded version of why, and it is an enum precisely so no provider sentence
 * can be carried in it.
 *
 * @mixin ProvisioningJob
 */
final class CustomerOperationResource extends JsonResource
{
    /**
     * How long a client should wait before reading again.
     *
     * Two speeds, because the first few seconds of an operation are when a
     * customer is actually watching: a queued or freshly started job is worth
     * asking about every three seconds, and one that has been running for a
     * while is not. The client applies its own backoff on top; this is the
     * floor the server is willing to serve.
     */
    private const POLL_SOON_MS = 3000;

    private const POLL_LATER_MS = 10000;

    /** After this long, "running" is more likely to be stuck than quick. */
    private const SETTLING_SECONDS = 30;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProvisioningJob $job */
        $job = $this->resource;

        $state = $this->state($job->status);

        /** @var array<string, mixed> $payload */
        $payload = $job->payload ?? [];

        return [
            'id' => $job->id,
            'kind' => $job->kind->value,

            /*
             * The customer's own verb where the payload carries one. The kind
             * cannot: `stop` and `shutdown` are one kind, and reporting "stop"
             * for a graceful shutdown tells the customer the plug was pulled.
             */
            'action' => is_string($payload['power_action'] ?? null) ? $payload['power_action'] : null,

            'state' => $state->value,
            'is_terminal' => $state->isTerminal(),
            'needs_attention' => $state->needsAttention(),
            'retry_advice' => $state->retryAdvice()->value,

            // The customer's version of why, or null. Never the provider's.
            'failure_reason' => CustomerFailureReason::for($job->failure_class)?->value,

            /*
             * What to watch, so the client does not have to guess. §16: the
             * server makes the follow-up explicit rather than leaving React to
             * infer that a reboot means refetching some list.
             */
            'resource' => self::watching($job),

            'started_at' => $job->started_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),

            'poll_after_ms' => $this->pollAfterMs($job, $state),
        ];
    }

    /**
     * What to read again once this finishes, or null.
     *
     * A service id rather than a resource handle: the client already turns a
     * service into an address through one function, and resolving the
     * fulfilling row here would make every status poll pay for a join it does
     * not need.
     *
     * @return ?array{service_id: string}
     */
    private static function watching(ProvisioningJob $job): ?array
    {
        if ($job->service_id === null) {
            return null;
        }

        return ['service_id' => (string) $job->service_id];
    }

    /**
     * The engine's status, in the customer's words.
     *
     * No default arm: a status added to the engine fails here rather than
     * being reported as something it is not.
     */
    private function state(ProvisioningJobStatus $status): CustomerOperationState
    {
        return match ($status) {
            ProvisioningJobStatus::Queued => CustomerOperationState::Queued,
            ProvisioningJobStatus::Running => CustomerOperationState::Processing,
            ProvisioningJobStatus::Succeeded => CustomerOperationState::Succeeded,
            ProvisioningJobStatus::Failed => CustomerOperationState::Failed,
            ProvisioningJobStatus::NeedsReview => CustomerOperationState::NeedsReview,
            ProvisioningJobStatus::Cancelled => CustomerOperationState::Cancelled,
        };
    }

    /**
     * Null once there is nothing left to wait for.
     *
     * This is the server half of "polling stops on terminal states": a client
     * that only ever schedules the next read from this field cannot keep
     * asking about an operation that finished an hour ago, however the screen
     * that started it was written.
     */
    private function pollAfterMs(ProvisioningJob $job, CustomerOperationState $state): ?int
    {
        if ($state->isTerminal()) {
            return null;
        }

        $started = $job->started_at;

        if ($started === null) {
            return self::POLL_SOON_MS;
        }

        return $started->diffInSeconds(now()) < self::SETTLING_SECONDS
            ? self::POLL_SOON_MS
            : self::POLL_LATER_MS;
    }
}
