<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Exceptions;

use RuntimeException;

/**
 * A machine has nothing bound to it that could reach it.
 *
 * Not a failure of the machine and not a failure of a credential: the
 * platform does not know which adapter to speak to its management controller
 * with, because no BMC provider has been registered against it. The fix is a
 * registration, and the message says so.
 */
final class NoBmcProvider extends RuntimeException
{
    public static function forServer(string $server): self
    {
        return new self(sprintf(
            'No BMC provider is bound to %s, so there is nothing to reach it with. '
            .'Register one — IPMI, Redfish or iLO — against this machine first. '
            .'The driver is never taken from the request: a caller that could name it could point a test at any adapter.',
            $server,
        ));
    }
}
