<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Http\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Provisioning\Application\Actions\AdoptOrphanResource;
use Lynomia\Modules\Provisioning\Application\Actions\RetryProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * The provisioning queue, which is where an operator looks when a customer
 * says their server never arrived.
 *
 * Unlike the customer surface, this publishes `last_error` and `failure_class`.
 * That is the point of the screen: the operator needs the provider's own words.
 * The value is already redacted by the platform's log processor before it is
 * stored, so what is shown is the message with its credentials masked, not a
 * raw provider response.
 */
final class ProvisioningController
{
    use ListsAcrossTenants;

    public function index(Request $request): JsonResponse
    {
        $jobs = ProvisioningJob::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->when($request->filled('kind'), fn ($query) => $query->where('kind', $request->string('kind')->value()))
            ->when($request->filled('customer_id'), fn ($query) => $query->where('customer_id', $request->string('customer_id')->value()))
            /*
             * Newest first, and the ULID breaks ties: two jobs created in the
             * same millisecond by one order must not be able to swap places
             * between pages.
             */
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($jobs, static fn (ProvisioningJob $job): array => [
            'id' => $job->id,
            'kind' => $job->kind->value,
            'status' => $job->status->value,
            'provider' => $job->provider,
            'customer_id' => $job->customer_id,
            'service_id' => $job->service_id,
            'attempts' => $job->attempts,
            'max_attempts' => $job->max_attempts,
            'failure_class' => $job->failure_class?->value,
            'last_error' => $job->last_error,
            'correlation_id' => $job->correlation_id,
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'next_attempt_at' => $job->next_attempt_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
        ]);
    }

    /**
     * The jobs that need a human.
     *
     * A convenience over the filter above, and the one screen an operator
     * should be able to reach without composing a query: a timed-out job is
     * never retried automatically — the platform stopped waiting, the provider
     * may not have — so somebody has to look at every one of them.
     */
    public function needingReview(Request $request): JsonResponse
    {
        $jobs = ProvisioningJob::query()
            ->whereIn('status', ['failed', 'needs_review'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($jobs, static fn (ProvisioningJob $job): array => [
            'id' => $job->id,
            'kind' => $job->kind->value,
            'status' => $job->status->value,
            'customer_id' => $job->customer_id,
            'failure_class' => $job->failure_class?->value,
            'last_error' => $job->last_error,
            'attempts' => $job->attempts,
            'created_at' => $job->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Put a stopped job back into the pool.
     *
     * The safe half of operator recovery, and the one an NOC shift reaches for
     * most: a build that failed on a full cluster or a control panel that was
     * briefly down will succeed on a second run, and until now the only way to
     * ask for one was an UPDATE statement.
     *
     * What makes it safe is that the action refuses every case where running
     * the job again would cause a second event rather than repeat an attempt —
     * a resource already built, a disk already replaced — and there is no flag
     * here that can override it. The evidence the operator checked is required
     * and audited, because "I looked at the cluster and it has room now" is the
     * whole justification for the retry.
     */
    public function retry(Request $request, string $job): JsonResponse
    {
        $found = ProvisioningJob::query()->findOrFail($job);

        $validated = $request->validate([
            'evidence' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $user = $request->user();

        $requeued = app(RecordActAtomically::class)->execute(
            act: static fn (): ProvisioningJob => app(RetryProvisioningJob::class)->execute($found),
            describe: static fn (ProvisioningJob $job): AuditedAct => new AuditedAct(
                action: AuditAction::ProvisioningRetried,
                subject: $job,
                customerId: $job->customer_id,
                context: [
                    'evidence' => $validated['evidence'],
                    'kind' => $job->kind->value,
                    'attempts' => $job->attempts,
                    'service_id' => $job->service_id,
                    'retried_by' => $user instanceof User
                        ? sprintf('%s <%s>', $user->name, $user->email)
                        : 'system',
                ],
            ),
        );

        return response()->json([
            'data' => [
                'id' => $requeued->id,
                'status' => $requeued->status->value,
                'attempts' => $requeued->attempts,
                'max_attempts' => $requeued->max_attempts,
                'service_id' => $requeued->service_id,
            ],
        ]);
    }

    /**
     * Record that a resource the provider already built belongs to this job.
     *
     * The other half of never retrying a timeout, and the half that had no
     * execution path. AdoptOrphanResource has always been able to do this
     * correctly — refusing a running job, a settled one, and a reference
     * another job already claims — and nothing could call it. So a job in
     * needs_review stayed there: the machine existed at the hypervisor,
     * unbilled and unmanaged, holding an address the next customer was about
     * to be given, and the only alternative on offer was to build a second one
     * and charge for it.
     *
     * The operator is asserting something the platform could not check for
     * itself — "I looked at the hypervisor and that machine is ours" — so the
     * evidence they looked at is required, not optional, and the whole thing
     * lands in the audit trail.
     */
    public function adopt(Request $request, string $job): JsonResponse
    {
        $found = ProvisioningJob::query()->findOrFail($job);

        $validated = $request->validate([
            'provider_reference' => ['required', 'string', 'max:255'],
            'remote_job_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'evidence' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $user = $request->user();

        $adopted = app(RecordActAtomically::class)->execute(
            act: static fn (): ProvisioningJob => app(AdoptOrphanResource::class)->execute(
                job: $found,
                providerReference: $validated['provider_reference'],
                remoteJobId: $validated['remote_job_id'] ?? null,
                evidence: ['note' => $validated['evidence']],
                adoptedBy: $user instanceof User
                    ? sprintf('%s <%s>', $user->name, $user->email)
                    : 'system',
            ),
            describe: static fn (ProvisioningJob $job): AuditedAct => new AuditedAct(
                action: AuditAction::OrphanAdopted,
                subject: $job,
                customerId: $job->customer_id,
                context: [
                    'provider_reference' => $validated['provider_reference'],
                    'remote_job_id' => $validated['remote_job_id'] ?? null,
                    'evidence' => $validated['evidence'],
                    'service_id' => $job->service_id,
                ],
            ),
        );

        return response()->json([
            'data' => [
                'id' => $adopted->id,
                'status' => $adopted->status->value,
                'service_id' => $adopted->service_id,
            ],
        ]);
    }
}
