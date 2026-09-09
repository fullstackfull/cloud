<?php

declare(strict_types=1);

namespace Lynomia\Modules\Infrastructure\Application\Actions;

use Carbon\CarbonImmutable;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Infrastructure\Domain\Enums\DeploymentState;
use Lynomia\Modules\Infrastructure\Infrastructure\Models\DeploymentJob;
use Lynomia\Modules\Provisioning\Domain\Enums\FailureClass;

/**
 * The other half of the Timeout Rule.
 *
 * A worker that died mid-playbook leaves a job Applying for ever, and a job
 * that is Applying for ever holds the machine's one-in-flight slot for ever.
 * This marks anything that has been applying or verifying past the stale
 * deadline as indeterminate — not failed, because nobody knows — so it
 * shows up where a person will see it. It never restarts anything.
 */
final readonly class DetectStaleDeployments
{
    public function __construct(
        private RecordActAtomically $record,
    ) {}

    /**
     * @return array{examined: int, marked: int}
     */
    public function execute(): array
    {
        $deadline = CarbonImmutable::now()->subSeconds((int) config('infrastructure.stale_after_seconds', 2100));

        $stale = DeploymentJob::query()
            ->whereIn('state', [DeploymentState::Applying->value, DeploymentState::Verifying->value])
            ->where('started_at', '<=', $deadline)
            ->get();

        foreach ($stale as $job) {
            $this->record->execute(
                act: function () use ($job, $deadline): DeploymentJob {
                    $job->forceFill([
                        'state' => DeploymentState::Indeterminate,
                        'failure_class' => FailureClass::Timeout->value,
                        'failure_detail' => sprintf('Still %s at %s with no result from the worker. The machine may be part-way through the change.', $job->state->value, $deadline->toIso8601String()),
                        'finished_at' => CarbonImmutable::now(),
                    ])->save();

                    return $job;
                },
                describe: fn (DeploymentJob $marked): AuditedAct => new AuditedAct(
                    action: AuditAction::DeploymentFinished,
                    subject: $marked,
                    context: ['server' => $marked->server?->name, 'kind' => $marked->kind, 'state' => 'indeterminate', 'failure_class' => 'timeout', 'detail' => 'stale', 'facts' => 0],
                ),
            );
        }

        return ['examined' => $stale->count(), 'marked' => $stale->count()];
    }
}
