<?php

declare(strict_types=1);

namespace Lynomia\Modules\Provisioning\Domain\Enums;

/**
 * The shapes disagreement between the platform and a provider can take.
 *
 * `orphan_at_provider` is the one this whole module is built around: a
 * resource the provider has and the platform does not. It is the visible
 * symptom of a timed-out create, and the reason a timeout is never retried
 * automatically.
 */
enum DriftKind: string
{
    /** The platform believes it exists; the provider has never heard of it. */
    case MissingAtProvider = 'missing_at_provider';

    /** The provider has it; no service claims it. */
    case OrphanAtProvider = 'orphan_at_provider';

    /** Both agree it exists, but disagree about what it is doing. */
    case StateMismatch = 'state_mismatch';

    /** Both agree it exists, but disagree about how big it is. */
    case SpecMismatch = 'spec_mismatch';

    /**
     * Whether this drift may be costing money nobody is billing for, which is
     * what an operator triages first.
     */
    public function isBillingRelevant(): bool
    {
        return match ($this) {
            self::OrphanAtProvider, self::SpecMismatch => true,
            self::MissingAtProvider, self::StateMismatch => false,
        };
    }
}
