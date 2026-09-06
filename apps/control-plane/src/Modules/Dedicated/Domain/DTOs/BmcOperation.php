<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\DTOs;

use Lynomia\Modules\Dedicated\Domain\Enums\BmcProtocol;
use Lynomia\Modules\Dedicated\Domain\Enums\PowerState;

/**
 * A physical change the controller has accepted.
 *
 * Every mutation returns one of these even where the protocol answers
 * synchronously, for the same reason the compute module insists on a task id:
 * the platform must be able to say afterwards WHICH machine it acted on and
 * WHAT it asked for, without re-deriving either from the call site that has
 * since been unwound by a failure.
 *
 * `endpointId` is on the value rather than assumed by the caller because the
 * mistake it guards against is unrecoverable. A wrong VM id destroys one
 * customer's machine; a wrong BMC address power cycles a physical host and
 * every tenant sharing it.
 *
 * @immutable
 */
final readonly class BmcOperation
{
    /**
     * @param  string  $operation  A stable verb — "power_on", "set_one_time_pxe" — used in logs and
     *                             audit records, never shown to a customer.
     * @param  ?string  $taskId  The controller's own handle where it issues one. Redfish returns a
     *                           task for long-running work; IPMI never does, so it is null there and
     *                           the caller must not depend on it.
     * @param  ?PowerState  $resultingPowerState  What the machine is expected to be doing as a result.
     *                                            Null when the operation does not change power, or when
     *                                            the controller did not say.
     * @param  array<string, mixed>  $metadata  Redacted controller detail, safe to persist.
     */
    public function __construct(
        public string $operation,
        public string $endpointId,
        public BmcProtocol $protocol,
        public bool $accepted = true,
        public ?string $taskId = null,
        public ?PowerState $resultingPowerState = null,
        public array $metadata = [],
    ) {}

    /**
     * Whether the caller still has to ask the controller what happened.
     *
     * A task id means the controller is working on it; its absence means the
     * protocol reported completion inline and there is nothing to poll.
     */
    public function isInFlight(): bool
    {
        return $this->taskId !== null;
    }
}
