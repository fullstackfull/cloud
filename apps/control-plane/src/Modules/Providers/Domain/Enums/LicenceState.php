<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Enums;

/**
 * Whether a commercial licence is in force.
 *
 * NotRequired is a real answer and belongs here: Cloudflare needs no licence,
 * and a screen that shows "Missing" for it teaches operators to ignore the
 * column.
 */
enum LicenceState: string
{
    case NotRequired = 'not_required';
    case Missing = 'missing';
    case Pending = 'pending';
    case Active = 'active';
    case Expiring = 'expiring';
    case Expired = 'expired';
    case Invalid = 'invalid';
    case Unknown = 'unknown';

    /** Does this state permit the thing the licence covers? */
    public function permits(): bool
    {
        return $this === self::NotRequired || $this === self::Active || $this === self::Expiring;
    }

    /** Should somebody be told about this now? */
    public function needsAttention(): bool
    {
        return match ($this) {
            self::NotRequired, self::Active => false,
            default => true,
        };
    }
}
