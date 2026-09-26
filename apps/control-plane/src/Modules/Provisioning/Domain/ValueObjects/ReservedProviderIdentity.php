<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\ValueObjects;

/**
 * The identity a create job asks its provider for, as the job row holds it
 * after the reservation statement has run.
 *
 * Read back from the row rather than assembled from what the caller asked
 * for, because the two can differ: the first attempt to reach the provider
 * call fixes the id and the cluster, and a later attempt asking for another
 * id is given the one already held. The caller must use this object's id for
 * its call, not its own.
 *
 * @immutable
 */
final readonly class ReservedProviderIdentity
{
    /**
     * @param  list<string>  $nodes  Every node an attempt under this id was placed on, and so every node a create under it can have been sent to.
     * @param  list<string>  $hostnames  Every name a create under this id was sent with, written immediately before each was sent — never a name only reserved.
     */
    public function __construct(
        public string $providerId,
        public string $clusterId,
        public array $nodes,
        public array $hostnames,
    ) {}

    /**
     * Whether the hypervisor's name for a machine is one a create under this
     * id was sent with. With none sent, no name is.
     *
     * Exact, deliberately: no case folding and no trimming. A comparison
     * loosened in either direction widens what this job may claim as its own
     * build — and what it claims, it may later be allowed to build around.
     */
    public function calledWith(string $name): bool
    {
        return in_array($name, $this->hostnames, true);
    }
}
