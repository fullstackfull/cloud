<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentApproval;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentPlan;

/**
 * A person saying yes to exactly this plan.
 *
 * The approval records the fingerprint as it stood. The run compares it
 * again; a plan that changed between approval and run does not run. Four
 * eyes: the person who planned it does not approve it. And a destructive
 * plan needs the machine's own clearance, typed by name, which the plan's
 * blockers already demand — an approval cannot be given to a plan with
 * blockers at all.
 */
final readonly class ApproveDeploymentPlan
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(DeploymentPlan $plan, User $approver, string $reason): DeploymentApproval
    {
        return $this->record->execute(
            act: function () use ($plan, $approver, $reason): DeploymentApproval {
                $locked = DeploymentPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
                $server = $locked->server;

                $current = $server->plans()->latest('created_at')->first();

                if ($current === null || $current->getKey() !== $locked->getKey()) {
                    throw DeploymentRefused::planSuperseded();
                }

                if (! $locked->is_applicable) {
                    throw DeploymentRefused::planNotApplicable($server->name);
                }

                if ($locked->planned_by !== null && $locked->planned_by === $approver->getKey()) {
                    throw DeploymentRefused::approvedByPlanner();
                }

                $standing = $locked->standingApproval();

                if ($standing !== null) {
                    return $standing;
                }

                return DeploymentApproval::query()->create([
                    'deployment_plan_id' => $locked->getKey(),
                    'approved_fingerprint' => $locked->fingerprint,
                    'approved_by' => $approver->getKey(),
                    'reason' => $reason,
                    'approved_at' => CarbonImmutable::now(),
                ]);
            },
            describe: fn (DeploymentApproval $approval): AuditedAct => new AuditedAct(
                action: AuditAction::PlanApproved,
                subject: $approval,
                context: [
                    'plan' => $plan->getKey(),
                    'server' => $plan->server?->name,
                    'fingerprint' => $approval->approved_fingerprint,
                    'risk' => $plan->risk->value,
                    'destructive' => $plan->is_destructive,
                    'reason' => $reason,
                    'operator' => $approver->getKey(),
                ],
            ),
        );
    }
}
