<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Preflight;

/**
 * The four rungs this project has always used to describe how much is known,
 * kept separate here because a preflight is exactly where they get collapsed.
 *
 * The collapse happens like this: a simulation run goes green, somebody
 * screenshots it, and the screenshot is read as "the infrastructure works".
 * Every rung below the top is about software; only the top is about an estate,
 * and it is earned per capability rather than per provider.
 */
enum VerificationLevel: string
{
    /** The code exists and is wired up. */
    case CodeComplete = 'CODE_COMPLETE';

    /** Tests cover it, including its failure paths. */
    case Tested = 'TESTED';

    /** It has been executed and observed, against controlled providers. */
    case RuntimeVerified = 'RUNTIME_VERIFIED';

    /**
     * A real provider answered.
     *
     * Only ever attached to a specific check, with the check's own id, and
     * only when that check's evidence class is
     * {@see EvidenceClass::RealRead}. There is no path by which a whole
     * provider, product or estate acquires this.
     */
    case RealInfraVerified = 'REAL_INFRA_VERIFIED';
}
