<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\DTOs;

use Lynomia\Modules\SharedHosting\Domain\Enums\HostingPanel;

/**
 * The question the scheduler answers: where does this account go?
 *
 * The package is passed by id rather than by its quotas, because the quotas
 * are what the node has to have room for and they live on one authoritative
 * row. A request that carried a copy of them would let a package edit and a
 * placement disagree, and the disagreement would only show up as a node that
 * filled faster than the scheduler believed.
 *
 * @immutable
 */
final readonly class HostingPlacementRequest
{
    /**
     * @param  string|null  $regionId  Where the customer bought. A hosting account holds mail and
     *                                 a database as well as files, so placing one outside the
     *                                 region the customer chose is a data-residency decision, not
     *                                 a latency one.
     * @param  HostingPanel|null  $panel  Set when the customer's plan is sold as a specific panel.
     *                                    Customers buy "cPanel hosting" by name and their existing
     *                                    backups are panel-shaped, so this is a hard filter.
     * @param  list<string>  $excludedNodeIds  Nodes the caller has already tried, or wants avoided.
     */
    public function __construct(
        public string $packageId,
        public ?string $regionId = null,
        public ?HostingPanel $panel = null,
        public ?string $customerId = null,
        public array $excludedNodeIds = [],
    ) {}
}
