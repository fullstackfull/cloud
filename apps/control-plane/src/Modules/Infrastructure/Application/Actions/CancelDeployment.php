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
 * Only a run that has not started can be cancelled. One that is applying is
 * a playbook in flight, and there is no cancel for that which leaves the
 * machine in a known state; it finishes, or it goes indeterminate.
 */
final readonly class CancelDeployment
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    public function execute(DeploymentJob $job, User $operator, string $reason): DeploymentJob
    {
        return $this->record->execute(
            act: function () use ($job, $reason): DeploymentJob {
                $locked = DeploymentJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();

                if (! in_array($locked->state, [DeploymentState::Requested, DeploymentState::Queued, DeploymentState::AwaitingApproval], strict: true)) {
                    throw DeploymentRefused::notCancellable($locked->state->value);
                }

                $locked->forceFill([
                    'state' => DeploymentState::Cancelled,
                    'failure_detail' => $reason,
                    'finished_at' => CarbonImmutable::now(),
                ])->save();

                return $locked;
            },
            describe: fn (DeploymentJob $cancelled): AuditedAct => new AuditedAct(
                action: AuditAction::DeploymentCancelled,
                subject: $cancelled,
                context: ['server' => $cancelled->server?->name, 'reason' => $reason, 'operator' => $operator->getKey()],
            ),
        );
    }
}
