<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Contracts;

use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;

/**
 * The seam through which an adoption moves what the job holds to where the
 * adopted resource is.
 *
 * A job's reservations are taken where its attempts were placed, and an
 * adoption says where the resource actually is - which is not always the same
 * place. A build whose first create landed late on one node, after a retry
 * had moved its commitment to another, is adopted on the first: left alone,
 * the commitment charges a node for a machine it does not run and the node
 * that runs it is charged nothing (D5, round six). And a job whose
 * reservations were already given back - released on a failure taken for
 * "nothing was built" - holds none to move: they are taken again where the
 * resource is, or the resource is charged to nothing. Which rows those are, and
 * how to find where the resource is, belongs to the module that reserved
 * them, as release and quarantine do (ResourceReservationReleaser).
 */
interface ReservationsFollowAnAdoption
{
    /**
     * Called inside the adoption's transaction, after its checks, with the
     * job locked. Returns what it found and did, for the adoption's record
     * (empty when it has nothing to say about this job).
     *
     * @return array<string, scalar|null>
     */
    public function follow(ProvisioningJob $job, string $providerReference): array;
}
