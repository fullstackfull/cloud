<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure\Queries;

use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Domain\Services\VpsOperationGuard;

/**
 * Why the operation guard would refuse each of a set of services, if it would.
 *
 * The same three statuses {@see VpsOperationGuard::assertNothingInFlight()}
 * reads — queued, running, needs_review — asked once for a page of machines
 * rather than once per row, so the list endpoint can publish "this button
 * will be refused, and why" without a query per machine.
 *
 * The answer is one word per service: `in_flight` when a job is queued or
 * running (it will finish on its own), `needs_review` when an earlier job
 * stopped and is waiting for a person (it will not). Live work wins over
 * stranded work, matching the guard's own precedence: "wait for this to
 * finish" is the answer a customer can act on.
 */
final class UnresolvedServiceWork
{
    public const string IN_FLIGHT = 'operation_in_flight';

    public const string NEEDS_REVIEW = 'operation_needs_review';

    /**
     * @param  list<string>  $serviceIds
     * @return array<string, string> service id → reason, absent when nothing is unresolved
     */
    public static function forServices(array $serviceIds): array
    {
        $serviceIds = array_values(array_filter($serviceIds, static fn (string $id): bool => $id !== ''));

        if ($serviceIds === []) {
            return [];
        }

        $jobs = ProvisioningJob::query()
            ->whereIn('service_id', $serviceIds)
            ->whereIn('status', [
                ProvisioningJobStatus::Queued->value,
                ProvisioningJobStatus::Running->value,
                ProvisioningJobStatus::NeedsReview->value,
            ])
            ->get(['service_id', 'status']);

        $reasons = [];

        foreach ($jobs as $job) {
            $serviceId = (string) $job->service_id;
            $reason = $job->status->needsAttention() ? self::NEEDS_REVIEW : self::IN_FLIGHT;

            // Live work takes precedence, whichever row the query returned first.
            if (($reasons[$serviceId] ?? null) !== self::IN_FLIGHT) {
                $reasons[$serviceId] = $reason;
            }
        }

        return $reasons;
    }
}
