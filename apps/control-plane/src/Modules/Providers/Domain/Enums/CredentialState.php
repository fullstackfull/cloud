<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Enums;

/**
 * Whether a credential exists and works.
 *
 * The platform never stores the credential itself — only a reference into the
 * secret backend, and this state. Configured means a reference is recorded;
 * Valid means somebody used it successfully. The two are different, and
 * treating them as one is how a provider looks healthy on a screen while every
 * call it makes is rejected.
 */
enum CredentialState: string
{
    case Missing = 'missing';
    case Configured = 'configured';
    case Untested = 'untested';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case RotationDue = 'rotation_due';
    case Revoked = 'revoked';

    public function usable(): bool
    {
        return $this === self::Valid || $this === self::RotationDue;
    }

    /** Present, but nobody has confirmed it works. */
    public function unproven(): bool
    {
        return $this === self::Configured || $this === self::Untested;
    }
}
