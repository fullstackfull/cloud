<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentApproval;

/**
 * Taking a yes back, by a person or by the plan changing underneath it.
 * Either way the row stays with its reason, so "why did this not run" has an
 * answer.
 */
final readonly class RevokeDeploymentApproval
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(DeploymentApproval $approval, ?User $operator, string $reason, bool $automatic = false): DeploymentApproval
    {
        return $this->record->execute(
            act: function () use ($approval, $reason): DeploymentApproval {
                if ($approval->revoked_at === null) {
                    $approval->forceFill([
                        'revoked_at' => CarbonImmutable::now(),
                        'reason' => trim((string) $approval->reason."\nRevoked: ".$reason),
                    ])->save();
                }

                return $approval;
            },
            describe: fn (DeploymentApproval $revoked): AuditedAct => new AuditedAct(
                action: AuditAction::PlanApprovalRevoked,
                subject: $revoked,
                context: [
                    'plan' => $revoked->deployment_plan_id,
                    'fingerprint' => $revoked->approved_fingerprint,
                    'reason' => $reason,
                    'automatic' => $automatic,
                    'operator' => $operator?->getKey(),
                ],
            ),
        );
    }
}
