<?php

declare(strict_types=1);

namespace Lynomia\Modules\Ipam\Application\DTOs;

/**
 * What a run of SeedSubnetAddresses actually did.
 *
 * `inserted` is deliberately separate from `total`: on a re-run the first is
 * zero and the second is unchanged, which is how an operator sees at a glance
 * that the seed was idempotent rather than duplicated.
 *
 * @immutable
 */
final readonly class SubnetSeedResult
{
    /**
     * @param  list<string>  $unavailableAddresses  Network, broadcast and gateway — the addresses that exist as
     *                                              rows so nothing can invent them, and are never allocatable.
     * @param  list<string>  $conflictingAddresses  Non-host addresses that are already reserved or assigned to
     *                                              somebody. The seeder refuses to overwrite these; they need an
     *                                              operator, because something is using an address it must not.
     */
    public function __construct(
        public string $subnetId,
        public string $cidr,
        public int $inserted,
        public int $total,
        public int $allocatable,
        public array $unavailableAddresses = [],
        public array $conflictingAddresses = [],
    ) {}

    /** True when the run found nothing to do, which is the steady state. */
    public function wasNoOp(): bool
    {
        return $this->inserted === 0;
    }

    public function needsOperatorAttention(): bool
    {
        return $this->conflictingAddresses !== [];
    }
}
