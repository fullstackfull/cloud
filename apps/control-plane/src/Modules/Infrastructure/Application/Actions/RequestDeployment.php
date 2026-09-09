<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Illuminate\Database\QueryException;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Application\Jobs\RunDeploymentJob;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentKind;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Domain\Enums\InfrastructureAction;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Domain\Services\SafetyGate;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\ManagedServer;

/**
 * Start a run — or rather, queue one; the worker starts it.
 *
 * Everything that could refuse, refuses here, before a row exists: the
 * machine's classification for the action, a current applicable plan, a
 * standing approval whose fingerprint is that plan's, and no other run in
 * flight (the database's partial unique index is the last word on that). A
 * verify needs no plan and no approval: it looks and changes nothing.
 */
final readonly class RequestDeployment
{
    public function __construct(
        private SafetyGate $gate,
        private RecordActAtomically $record,
    ) {}

    public function execute(ManagedServer $server, DeploymentKind $kind, User $operator): DeploymentJob
    {
        $plan = null;
        $approval = null;

        if ($kind === DeploymentKind::Apply) {
            $plan = $server->plans()->latest('created_at')->first() ?? throw DeploymentRefused::noPlan($server->name);

            if (! $plan->is_applicable) {
                throw DeploymentRefused::planNotApplicable($server->name);
            }

            $approval = $plan->standingApproval() ?? throw DeploymentRefused::notApproved($server->name, $plan->fingerprint);

            $this->gate->assert(
                $server->name,
                $server->safety_class,
                $server->allow_reimage,
                $plan->is_destructive ? InfrastructureAction::Reimage : InfrastructureAction::Configure,
            );
        } else {
            $this->gate->assert($server->name, $server->safety_class, $server->allow_reimage, InfrastructureAction::Read);
        }

        // The Timeout Rule, enforced at the door: a machine with a run that
        // stopped without a result is not touched again until a person has
        // said what they found.
        $unresolved = DeploymentJob::query()
            ->where('managed_server_id', $server->getKey())
            ->whereIn('state', [DeploymentState::Indeterminate->value, DeploymentState::NeedsReview->value])
            ->exists();

        if ($unresolved) {
            throw DeploymentRefused::unresolved($server->name);
        }

        try {
            $job = $this->record->execute(
                act: function () use ($server, $kind, $plan, $approval, $operator): DeploymentJob {
                    $attempt = DeploymentJob::query()
                        ->where('managed_server_id', $server->getKey())
                        ->when($approval !== null, fn ($q) => $q->where('deployment_approval_id', $approval?->getKey()))
                        ->count() + 1;

                    return DeploymentJob::query()->create([
                        'managed_server_id' => $server->getKey(),
                        'deployment_plan_id' => $plan?->getKey(),
                        'deployment_approval_id' => $approval?->getKey(),
                        'state' => DeploymentState::Queued,
                        'kind' => $kind->value,
                        // One row per approval per attempt: a redelivered
                        // request for the same approval is the same row, and a
                        // deliberate second run after a failure is a new one.
                        'idempotency_key' => hash('sha256', implode(':', [
                            $server->getKey(),
                            $kind->value,
                            $approval === null ? 'verify' : $approval->approved_fingerprint,
                            $approval?->getKey() ?? 'none',
                            (string) $attempt,
                        ])),
                        'requested_by' => $operator->getKey(),
                    ]);
                },
                describe: fn (DeploymentJob $job): AuditedAct => new AuditedAct(
                    action: AuditAction::DeploymentRequested,
                    subject: $job,
                    context: [
                        'server' => $server->name,
                        'kind' => $kind->value,
                        'plan' => $plan?->getKey(),
                        'fingerprint' => $approval?->approved_fingerprint,
                        'operator' => $operator->getKey(),
                    ],
                ),
            );
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'deployment_jobs_one_in_flight_per_server')) {
                throw DeploymentRefused::alreadyInFlight($server->name);
            }

            throw $e;
        }

        RunDeploymentJob::dispatch($job->getKey());

        return $job;
    }
}
