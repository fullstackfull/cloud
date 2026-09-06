<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Infrastructure\Releasers;

use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use RuntimeException;

/**
 * A releaser that holds no resources and reaches no other module.
 *
 * It exists so the engine can be exercised end to end — including the branch
 * that decides between releasing and quarantining, which is the most
 * consequential branch in the module — without the test suite depending on
 * IPAM's schema or on which addresses a fixture happened to reserve.
 *
 * It records what it was asked to do rather than doing nothing quietly,
 * because "compensation ran" and "compensation released the right things" are
 * different claims and a test that cannot tell them apart proves neither.
 */
final class FakeResourceReservationReleaser implements ResourceReservationReleaser
{
    /** @var list<array{action: string, job_id: string, reason: string|null}> */
    private array $calls = [];

    /**
     * @param  int  $reservationsPerJob  What this releaser pretends each job is holding, so a caller
     *                                   can assert on a count that is not always zero.
     */
    public function __construct(
        private readonly int $reservationsPerJob = 1,
    ) {
        /*
         * Not a DomainException: nothing about the domain has been violated.
         * This is a deployment assertion, and it fires at construction rather
         * than at first use because a fake bound in production must fail the
         * boot, not the first customer whose build times out.
         */
        if (app()->isProduction()) {
            throw new RuntimeException('The fake reservation releaser must never be bound in production.');
        }
    }

    public function release(ProvisioningJob $job): int
    {
        $this->calls[] = ['action' => 'release', 'job_id' => (string) $job->getKey(), 'reason' => null];

        return $this->reservationsPerJob;
    }

    public function quarantine(ProvisioningJob $job, string $reason): int
    {
        $this->calls[] = ['action' => 'quarantine', 'job_id' => (string) $job->getKey(), 'reason' => $reason];

        return $this->reservationsPerJob;
    }

    /**
     * @return list<array{action: string, job_id: string, reason: string|null}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map(static fn (array $call): string => $call['action'], $this->calls);
    }

    public function released(ProvisioningJob $job): bool
    {
        return $this->recorded('release', $job);
    }

    public function quarantined(ProvisioningJob $job): bool
    {
        return $this->recorded('quarantine', $job);
    }

    private function recorded(string $action, ProvisioningJob $job): bool
    {
        foreach ($this->calls as $call) {
            if ($call['action'] === $action && $call['job_id'] === (string) $job->getKey()) {
                return true;
            }
        }

        return false;
    }
}
