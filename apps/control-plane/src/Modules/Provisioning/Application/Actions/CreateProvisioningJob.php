<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Illuminate\Support\Str;
use Lynomia\Modules\Provisioning\Application\DTOs\ProvisioningJobRequest;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;

/**
 * The only door through which work enters the provisioning engine.
 *
 * The whole action is one idea: the idempotency key decides whether this is
 * new work, and the database decides that, not us.
 *
 * insertOrIgnore rather than firstOrCreate, and never a SELECT followed by an
 * INSERT. The check and the insert have to be a single statement or two
 * workers both pass the check — and here the consequence of both passing is
 * not a duplicate row in a log, it is two servers, two addresses out of the
 * pool and one of them billed to nobody. A unique violation swallowed by the
 * database is the cheapest possible way to be right.
 *
 * A repeated call returns the job that already exists, including when its
 * details differ. The key is the caller's assertion that this is the same
 * intent; honouring it is the point, and quietly rewriting an in-flight job's
 * payload to match a later request would be far worse than ignoring the
 * difference.
 */
final readonly class CreateProvisioningJob
{
    public function __construct(
        private SecretRedactor $redactor,
    ) {}

    public function execute(ProvisioningJobRequest $request): ProvisioningJob
    {
        $now = now();

        $inserted = ProvisioningJob::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'service_id' => $request->serviceId,
            'order_id' => $request->orderId,
            'customer_id' => $request->customerId,
            'requested_by_user_id' => $request->requestedByUserId,
            'idempotency_key' => $request->idempotencyKey,
            'kind' => $request->kind->value,
            'provider' => $request->provider,
            'status' => ProvisioningJobStatus::Queued->value,
            'attempts' => 0,
            'max_attempts' => $request->maxAttempts ?? (int) config('provisioning.retry.max_attempts', 3),
            'timeout_seconds' => $request->timeoutSeconds ?? $request->kind->defaultTimeoutSeconds(),
            // Redacted here rather than by the model: insertOrIgnore is a
            // query-builder write, and it never reaches the mutator that
            // protects every other path into this column.
            'payload' => json_encode($this->redactor->redact($request->payload), JSON_THROW_ON_ERROR),
            'correlation_id' => $request->correlationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::query()
            ->where('idempotency_key', $request->idempotencyKey)
            ->firstOrFail();

        // Set by hand because insertOrIgnore does not go through the model.
        // Callers use it to decide whether to dispatch a worker: dispatching
        // for a job that already existed is how a job that is mid-flight gets
        // a second worker.
        $job->wasRecentlyCreated = $inserted === 1;

        return $job;
    }
}
