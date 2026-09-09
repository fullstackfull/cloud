<?php

declare(strict_types=1);

namespace Lynomia\Modules\Estate\Domain\Enums;

/**
 * Which world a machine or provider instance belongs to.
 *
 * The load-bearing rule: a test credential never satisfies a production
 * requirement. Two Stripe accounts, two registrar accounts and two Proxmox
 * clusters are the normal shape, and letting the sandbox one stand in for the
 * live one is how a test card ends up charged for a real order — or worse, the
 * other way round.
 */
enum EstateEnvironment: string
{
    case Development = 'development';
    case Staging = 'staging';
    case Production = 'production';

    /** May something in this environment be used to satisfy a need in that one? */
    public function satisfies(self $required): bool
    {
        return $this === $required;
    }

    /** Is this the environment where a mistake reaches a paying customer? */
    public function isLive(): bool
    {
        return $this === self::Production;
    }
}
