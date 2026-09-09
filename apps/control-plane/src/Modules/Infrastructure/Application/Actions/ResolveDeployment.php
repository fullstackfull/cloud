<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Domain\Exceptions\DeploymentRefused;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;

/**
 * A person stating what they found.
 *
 * An indeterminate or needs-review deployment ends only this way: somebody
 * went and looked at the machine and says it is done, or says it is not. The
 * platform records the statement with its reason and moves on. It never
 * guesses, and it never writes "completed" on the strength of a timeout
 * that happened to be followed by silence.
 */
final readonly class ResolveDeployment
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(DeploymentJob $job, User $operator, bool $completed, string $reason): DeploymentJob
    {
        return $this->record->execute(
            act: function () use ($job, $completed, $reason): DeploymentJob {
                $locked = DeploymentJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();

                if (! $locked->state->waitsForSomebody() || $locked->state === DeploymentState::AwaitingApproval) {
                    throw DeploymentRefused::notWaiting($locked->state->value);
                }

                $locked->forceFill([
                    'state' => $completed ? DeploymentState::Completed : DeploymentState::Failed,
                    'failure_detail' => trim((string) $locked->failure_detail."\nResolved by a person: ".$reason),
                    'finished_at' => CarbonImmutable::now(),
                ])->save();

                if ($completed) {
                    // A person confirmed the machine is as planned. The next
                    // verification writes the facts; this does not invent them.
                    $locked->server?->forceFill(['last_deployment_at' => CarbonImmutable::now()])->save();
                }

                return $locked;
            },
            describe: fn (DeploymentJob $resolved): AuditedAct => new AuditedAct(
                action: AuditAction::DeploymentResolved,
                subject: $resolved,
                context: [
                    'server' => $resolved->server?->name,
                    'outcome' => $resolved->state->value,
                    'reason' => $reason,
                    'operator' => $operator->getKey(),
                ],
            ),
        );
    }
}
