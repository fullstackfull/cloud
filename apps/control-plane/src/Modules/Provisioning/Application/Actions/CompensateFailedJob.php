<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Application\Actions;

use Lynomia\Modules\Provisioning\Application\DTOs\CompensationOutcome;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Domain\Enums\CompensationAction;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * Decides what happens to the scarce resources a failed job was holding.
 *
 * There is one branch, and it is the most consequential branch in the module:
 *
 *  - transient, capacity and permanent failures RELEASE. Nothing was built, so
 *    the address and the capacity go straight back into the pool where the
 *    next customer can have them.
 *
 *  - a timeout QUARANTINES, and never releases. A timeout does not mean the
 *    provider failed; it means the platform stopped waiting. The machine may
 *    exist, running, configured with that address. Releasing it hands a live
 *    address to the next customer and produces a duplicate-IP incident that no
 *    retry logic can undo — and unlike a held address, that one is invisible
 *    until two customers are both broken.
 *
 * Which rows are affected is not this module's business. The releaser is bound
 * to IPAM by the application, so the engine can compensate without knowing
 * that addresses exist.
 */
final readonly class CompensateFailedJob
{
    public function __construct(
        private ResourceReservationReleaser $releaser,
    ) {}

    public function execute(ProvisioningJob $job, FailureClass $failureClass, ?string $reason = null): CompensationOutcome
    {
        $reason ??= $this->defaultReason($job, $failureClass);

        if ($failureClass->requiresQuarantine()) {
            $outcome = new CompensationOutcome(
                action: CompensationAction::Quarantined,
                failureClass: $failureClass,
                reservations: $this->releaser->quarantine($job, $reason),
                reason: $reason,
            );
        } else {
            $outcome = new CompensationOutcome(
                action: CompensationAction::Released,
                failureClass: $failureClass,
                reservations: $this->releaser->release($job),
                reason: $reason,
            );
        }

        $this->record($job, $outcome);

        return $outcome;
    }

    /**
     * Write what was decided onto the job.
     *
     * Months later, the only way to explain why an address is still held is to
     * be able to point at the row that says a timeout put it there.
     */
    private function record(ProvisioningJob $job, CompensationOutcome $outcome): void
    {
        $result = $job->result ?? [];
        $result['compensation'] = $outcome->toArray() + ['at' => now()->toIso8601String()];

        $job->result = $result;
        $job->save();
    }

    private function defaultReason(ProvisioningJob $job, FailureClass $failureClass): string
    {
        return $failureClass === FailureClass::Timeout
            ? sprintf('%s timed out; the resource may exist at the provider', $job->kind->value)
            : sprintf('%s failed with a %s error before anything was built', $job->kind->value, $failureClass->value);
    }
}
