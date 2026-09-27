<?php

declare(strict_types=1);

namespace Lynomia\Modules\Vps\Infrastructure;

use Lynomia\Modules\Compute\Domain\DTOs\RemoteVmState;

/**
 * What NodeCapacityFollowsAnAdoption asked the hypervisor before the
 * adoption's transaction, for it to act on under the job's lock.
 *
 * @immutable
 */
final readonly class WhereAnAdoptedMachineWasFound
{
    public function __construct(
        /** The cluster that was asked. Acted on only while the build still names it. */
        public string $clusterId,
        /** The node the machine was found on; null when it was not found, or not asked. */
        public ?string $nodeId = null,
        /** The machine as the hypervisor reported it there. */
        public ?RemoteVmState $machine = null,
        /** Why the hypervisor could not be asked, when it could not. */
        public ?string $unasked = null,
    ) {}
}
