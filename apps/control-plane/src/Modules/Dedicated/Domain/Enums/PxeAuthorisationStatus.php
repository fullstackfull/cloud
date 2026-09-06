<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dedicated\Domain\Enums;

/**
 * The life of one permission to reinstall a machine over the network.
 *
 * The authorisation exists so that a reinstall is a recorded decision with an
 * expiry rather than a standing configuration. Every state here is therefore
 * either "may still boot" or "may never boot again", and the transition
 * between the two is one-way.
 */
enum PxeAuthorisationStatus: string
{
    /** Granted, not yet used. */
    case Pending = 'pending';

    /** The machine has taken the network boot and the installer is running. */
    case Booted = 'booted';

    /** The install finished. The permission is spent. */
    case Completed = 'completed';

    /** The window elapsed without the machine booting. */
    case Expired = 'expired';

    /** Withdrawn by an operator, or by the platform after the BMC refused. */
    case Revoked = 'revoked';

    /** The machine booted and the install did not succeed. */
    case Failed = 'failed';

    /**
     * Whether this status still permits a network boot, ignoring the clock.
     *
     * The clock is checked separately and both must pass: a status alone can
     * never authorise a boot, or an authorisation left `pending` by a job that
     * died would be a standing invitation to reinstall.
     */
    public function permitsBoot(): bool
    {
        return $this === self::Pending || $this === self::Booted;
    }

    public function isFinished(): bool
    {
        return ! $this->permitsBoot();
    }
}
