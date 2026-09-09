<?php

declare(strict_types=1);

namespace Lynomia\Modules\Dns\Domain\Enums;

/**
 * How an attempt to apply an import ended. One row per attempt, so the
 * metric can say how often customers hit a refusal or a stale preview —
 * which is how a bad error message gets found.
 */
enum ZoneImportOutcome: string
{
    case Applied = 'applied';
    case Refused = 'refused';
    case PlanChanged = 'plan_changed';
}
