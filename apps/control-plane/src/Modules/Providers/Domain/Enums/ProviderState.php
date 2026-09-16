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

    /**
     * How far along onboarding this state is, from switched off to serving.
     *
     * The cases are ordered rather than derived from whether a blocker was
     * recorded, because an instance nobody has assessed has no blocker, and
     * reading that absence as "nothing is wrong" ranks a declared draft above
     * one that was assessed and needs a single named thing. Whoever is asked
     * to choose between them wants the second.
     */
    public function onboardingPosition(): int
    {
        return match ($this) {
            self::Disabled => 0,
            self::Draft => 1,
            self::Blocked => 2,
            self::Ready => 3,
            self::Enabled => 4,
        };
    }
}
