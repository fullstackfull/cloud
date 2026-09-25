<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Services;

use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Exceptions\ABuildMayExistException;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * Whether a service with no resource row here may still have something at a
 * provider — read from its build history, never from the missing row.
 *
 * ---------------------------------------------------------------------------
 * Why the row is not the evidence
 * ---------------------------------------------------------------------------
 *
 * A service whose build failed has no machine row, no account row, no chassis
 * assigned, and until F-19 it could not be ended at all: each kind's
 * termination refused a service with nothing to destroy, so the purchase it
 * belonged to could never end and never gave its plan unit or coupon hold
 * back. The tempting repair is to read the absent row as "never built".
 *
 * It is the wrong reading for exactly one failure, and that failure is the
 * dangerous one. Reading the absent row as "never built" turns the one
 * failure the engine refuses to retry AUTOMATICALLY — a timeout, excluded by
 * FailureClass::isAutomaticallyRetryable() because the provider may have
 * finished the work after the platform stopped waiting, and retrying it
 * builds a second machine — into the one failure an operator may close with a
 * single click. Nor can that exclusion be borrowed from the operator's side:
 * RetryProvisioningJob, the operator's button, does not read the failure
 * class at all. It refuses only on a recorded provider task or resource, so a
 * timed-out build with neither is one it will run again.
 *
 * So the history is read here, job by job, and any of these is evidence:
 *
 *  - a build job that has not settled — queued or running, it may yet build;
 *  - a build job that succeeded;
 *  - a provider task id or a provider resource reference on any build job;
 *  - a build job whose failure was classified as a timeout.
 *
 * Anything else — a refusal the provider gave before accepting work, a
 * capacity or transient failure that ran out of attempts, a cancelled job, or
 * no build job at all — left nothing behind, and a service with only that
 * history may be ended without asking any provider to destroy anything.
 *
 * ---------------------------------------------------------------------------
 * What this does not decide
 * ---------------------------------------------------------------------------
 *
 * Not whether a service WITH a resource row may end: that is each kind's own
 * termination, with its retention window and its override. This answers only
 * the question those actions cannot, which is what to do when there is no row
 * to hand them.
 */
final readonly class EvidenceOfABuild
{
    /**
     * What may exist at a provider for this service, as a short phrase, or
     * null when its build history shows nothing could have been built.
     *
     * @param  bool  $lock  take the build jobs' rows for update, in the order
     *                      RetryProvisioningJob takes them (job before
     *                      service), so an operator's retry cannot requeue a
     *                      build between this answer and the ending it permits
     */
    public function for(Service $service, bool $lock = false): ?string
    {
        $jobs = ProvisioningJob::query()
            ->where('service_id', $service->getKey())
            ->whereIn('kind', self::buildKinds())
            ->orderBy('created_at')
            ->orderBy('id')
            ->when($lock, static fn ($query) => $query->lockForUpdate())
            ->get();

        foreach ($jobs as $job) {
            $evidence = $this->in($job);

            if ($evidence !== null) {
                return $evidence;
            }
        }

        return null;
    }

    /**
     * @throws ABuildMayExistException
     */
    public function assertNothingWasBuiltFor(Service $service, bool $lock = false): void
    {
        $evidence = $this->for($service, $lock);

        if ($evidence !== null) {
            throw ABuildMayExistException::forService((string) $service->getKey(), $evidence);
        }
    }

    private function in(ProvisioningJob $job): ?string
    {
        $id = (string) $job->getKey();

        if (! $job->status->isSettled()) {
            return sprintf('build job %s has not finished', $id);
        }

        if ($job->status === ProvisioningJobStatus::Succeeded) {
            return sprintf('build job %s succeeded', $id);
        }

        $reference = ($job->result ?? [])['provider_reference'] ?? null;

        if (is_string($reference) && $reference !== '') {
            return sprintf('build job %s produced provider resource %s', $id, $reference);
        }

        if ($job->remote_job_id !== null && $job->remote_job_id !== '') {
            return sprintf('build job %s was accepted by the provider as task %s', $id, $job->remote_job_id);
        }

        if ($job->failure_class === FailureClass::Timeout) {
            return sprintf('build job %s timed out, and what the provider did after that is unknown', $id);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function buildKinds(): array
    {
        return array_values(array_map(
            static fn (ProvisioningJobKind $kind): string => $kind->value,
            array_filter(
                ProvisioningJobKind::cases(),
                static fn (ProvisioningJobKind $kind): bool => $kind->createsResource(),
            ),
        ));
    }
}
