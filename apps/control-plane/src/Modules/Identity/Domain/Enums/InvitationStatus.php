<?php

declare(strict_types=1);

namespace Lynomia\Modules\Identity\Domain\Enums;

/**
 * Where an invitation has got to.
 *
 * Derived from the timestamps on the row rather than stored, so there is no
 * second copy of the truth to fall out of step with them. An invitation that
 * was accepted and then expired is `Accepted`: the order below is the order
 * the model checks in, and a spent offer stays spent whatever the clock does
 * afterwards.
 */
enum InvitationStatus: string
{
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Pending = 'pending';

    /** Whether this offer can still be taken up. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
