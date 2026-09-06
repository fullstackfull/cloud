<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Contracts;

use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * The seam through which compensation reaches the scarce resources a job
 * reserved — addresses, capacity, licences.
 *
 * The engine deliberately does not depend on IPAM. It knows only that a failed
 * job may be holding something someone else needs, and that there are exactly
 * two safe things to do about it. Which rows those are, and what "holding"
 * means, belongs to the module that allocated them.
 *
 * The two methods are not interchangeable, and the difference is the entire
 * point of this interface:
 *
 *  - release() says nothing was built. The reservation goes back into the
 *    pool and may be handed to the next customer immediately.
 *  - quarantine() says we do not know whether anything was built. The
 *    reservation stays out of the pool until a person has looked, because an
 *    address given away while a machine that may exist is still configured
 *    with it produces a duplicate-IP incident that no amount of retry logic
 *    can undo.
 */
interface ResourceReservationReleaser
{
    /**
     * Hand back everything this job reserved.
     *
     * @return int The number of reservations released, for the audit record.
     */
    public function release(ProvisioningJob $job): int;

    /**
     * Hold everything this job reserved out of circulation, pending review.
     *
     * @return int The number of reservations quarantined.
     */
    public function quarantine(ProvisioningJob $job, string $reason): int;
}
