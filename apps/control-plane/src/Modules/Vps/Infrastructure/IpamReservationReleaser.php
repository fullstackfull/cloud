<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure;

use Illuminate\Support\Collection;
use Lynomia\Modules\Ipam\Domain\Enums\ReleaseReason;
use Lynomia\Modules\Ipam\Domain\Services\IpAllocator;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpReservation;
use Lynomia\Modules\Provisioning\Domain\Contracts\ResourceReservationReleaser;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * Binds the provisioning engine's compensation step to IPAM.
 *
 * The engine deliberately does not know that IP addresses exist — it knows only
 * that a failed job may hold reservations that have to be released or
 * quarantined, and which of the two depends on the failure class. This adapter
 * is where that decision meets the actual resource.
 *
 * The distinction it implements is the one that matters most in the whole
 * engine: a job that failed built nothing, so its address is free; a job that
 * TIMED OUT may well have configured the address on a machine that exists, so
 * handing it to the next customer would put two machines on one address. The
 * second case quarantines.
 */
final readonly class IpamReservationReleaser implements ResourceReservationReleaser
{
    public function __construct(
        private IpAllocator $allocator,
    ) {}

    public function release(ProvisioningJob $job): int
    {
        $released = 0;

        foreach ($this->liveReservationsFor($job) as $reservation) {
            $this->allocator->release($reservation, ReleaseReason::JobFailed);
            $released++;
        }

        return $released;
    }

    public function quarantine(ProvisioningJob $job, string $reason): int
    {
        $quarantined = 0;

        foreach ($this->liveReservationsFor($job) as $reservation) {
            /*
             * Released with a reason that sends the address to quarantine
             * rather than straight back to the pool. The machine may exist and
             * may be answering on this address right now; only an operator who
             * has checked the provider can safely say otherwise.
             */
            $this->allocator->release($reservation, ReleaseReason::ProvisioningTimedOut);
            $quarantined++;
        }

        return $quarantined;
    }

    /**
     * @return Collection<int, IpReservation>
     */
    private function liveReservationsFor(ProvisioningJob $job): Collection
    {
        return IpReservation::query()
            ->where('provisioning_job_id', $job->getKey())
            ->whereNull('released_at')
            ->get();
    }
}
