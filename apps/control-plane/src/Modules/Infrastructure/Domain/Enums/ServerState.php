<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Enums;

/**
 * Where a machine is in its life with us.
 *
 * Not the same thing as whether it is powered on — that is a fact discovered
 * from the BMC and changes minute to minute. This is the operator's view of
 * how far the machine has got through onboarding, and it moves only when
 * somebody does something.
 */
enum ServerState: string
{
    /** Recorded, nothing else. Somebody wrote down that a machine exists. */
    case Registered = 'registered';

    /** A credential is attached and a connection has been proven. */
    case Connected = 'connected';

    /** Facts have been read from it. */
    case Discovered = 'discovered';

    /** A software profile has been assigned; there is a desired state to compare against. */
    case Profiled = 'profiled';

    /** A deployment has run and verified. */
    case Managed = 'managed';

    /** Deliberately out of service; excluded from placement and from deployment. */
    case Retired = 'retired';

    public function inService(): bool
    {
        return match ($this) {
            self::Registered, self::Retired => false,
            default => true,
        };
    }
}
