<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Domain\Enums;

/**
 * What a deployment job is for.
 *
 * Apply changes the machine towards its desired state and then verifies.
 * Verify only looks — the same verification, run on its own, for a machine an
 * operator wants re-checked without changing anything.
 */
enum DeploymentKind: string
{
    case Apply = 'apply';
    case Verify = 'verify';

    public function requires(): InfrastructureAction
    {
        return match ($this) {
            self::Apply => InfrastructureAction::Configure,
            self::Verify => InfrastructureAction::Read,
        };
    }
}
