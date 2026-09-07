<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Lynomia\Modules\Admin\Http\Controllers\Concerns\ListsAcrossTenants;
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
}
