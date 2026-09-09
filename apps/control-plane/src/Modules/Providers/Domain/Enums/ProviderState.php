<?php

declare(strict_types=1);

namespace Lynomia\Modules\Providers\Domain\Enums;

/**
 * Where a provider instance is in its onboarding.
 *
 * Enabled is a decision somebody makes after the requirements pass, never a
 * side effect of a credential arriving. A payment provider in particular must
 * not become live merely because a key was pasted in.
 */
enum ProviderState: string
{
    /** Declared, nothing configured. */
    case Draft = 'draft';

    /** Everything it needs is present, and nobody has enabled it yet. */
    case Ready = 'ready';

    /** In use. The platform will route real work here. */
    case Enabled = 'enabled';

    /** Deliberately switched off by an operator. Existing resources are untouched. */
    case Disabled = 'disabled';

    /** Something it needs is missing. The instance's blocker says which. */
    case Blocked = 'blocked';

    public function serving(): bool
    {
        return $this === self::Enabled;
    }
}
