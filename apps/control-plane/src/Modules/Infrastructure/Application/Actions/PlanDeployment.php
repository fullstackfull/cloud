<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\DTOs\Plan;
use Lynomia\Modules\Infrastructure\Domain\DTOs\PlannedChange;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Domain\Services\PlanEngine;
use Lynomia\Modules\Infrastructure\Domain\Services\SoftwareCatalogue;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentPlan;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ServerFact;
use Lynomia\Modules\Providers\Infrastructure\Models\Licence;

/**
 * Compute what would be done to a machine, and record it.
 *
 * If the plan is the same plan — same fingerprint as the machine's current
 * plan — the current row is kept and any approval on it survives. If it
 * differs in anything, a new row is written and every standing approval on
 * the machine's older plans is revoked, audited as automatic: the thing that
 * was approved is no longer the thing that would run.
 */
final readonly class PlanDeployment
{
    public function __construct(
        private SoftwareCatalogue $catalogue,
        private PlanEngine $engine,
        private RevokeDeploymentApproval $revoke,
        private RecordActAtomically $record,
    ) {}

    public function execute(ManagedServer $server, ?User $operator = null): DeploymentPlan
    {
        $desired = $server->desiredState()->with('profile')->first()
            ?? throw DeploymentRefused::noDesiredState($server->name);

        $profile = $this->catalogue->profile((string) $desired->profile?->key)
            ?? throw DeploymentRefused::unknownProfile((string) $desired->profile?->key);

        $facts = ServerFact::query()
            ->where('managed_server_id', $server->getKey())
            ->current()
            ->pluck('value', 'key')
            ->all();

        $licensed = Licence::query()
            ->where('environment', $server->environment)
            ->get()
            ->filter(static fn (Licence $licence): bool => $licence->state->permits())
            ->pluck('product')
            ->unique()
            ->values()
            ->all();

        /** @var array<string, string> $overrides */
        $overrides = $desired->overrides ?? [];

        $plan = $this->engine->plan($profile, $overrides, $facts, $server->safety_class, $server->allow_reimage, $licensed);

        return $this->record->execute(
            act: function () use ($server, $plan, $operator): array {
                $current = $server->plans()->latest('created_at')->first();

                if ($current !== null && $current->fingerprint === $plan->fingerprint) {
                    // Same work, same fingerprint, same row: the approval
                    // stands. Only the evidence is refreshed.
                    $current->forceFill($this->attributes($plan))->save();

                    return ['plan' => $current, 'changed' => false, 'invalidated' => 0];
                }

                $invalidated = 0;

                foreach ($server->plans()->get() as $older) {
                    $approval = $older->standingApproval();

                    if ($approval !== null) {
                        $this->revoke->execute($approval, $operator, 'The plan changed; this approval covered a plan that will not run.', automatic: true);
                        $invalidated++;
                    }
                }

                $row = DeploymentPlan::query()->create([
                    'managed_server_id' => $server->getKey(),
                    'planned_by' => $operator?->getKey(),
                    ...$this->attributes($plan),
                ]);

                return ['plan' => $row, 'changed' => true, 'invalidated' => $invalidated];
            },
            describe: fn (array $outcome): ?AuditedAct => $outcome['changed'] ? new AuditedAct(
                action: AuditAction::PlanComputed,
                subject: $outcome['plan'],
                context: [
                    'server' => $server->name,
                    'profile' => $plan->profile,
                    'fingerprint' => $plan->fingerprint,
                    'changes' => count($plan->changes),
                    'blockers' => array_column($plan->blockers, 'code'),
                    'risk' => $plan->risk->value,
                    'approvals_invalidated' => $outcome['invalidated'],
                    'operator' => $operator?->getKey(),
                ],
            ) : null,
        )['plan'];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Plan $plan): array
    {
        return [
            'changes' => array_map(static fn (PlannedChange $c): array => $c->toArray(), $plan->changes),
            'unchanged' => $plan->unchanged,
            'blockers' => $plan->blockers,
            'risk' => $plan->risk,
            'requires_reboot' => $plan->requiresReboot,
            'requires_downtime' => $plan->requiresReboot,
            'is_destructive' => $plan->isDestructive,
            'required_safety_class' => $plan->requiredSafetyClass,
            'is_applicable' => $plan->isApplicable(),
            'fingerprint' => $plan->fingerprint,
        ];
    }
}
