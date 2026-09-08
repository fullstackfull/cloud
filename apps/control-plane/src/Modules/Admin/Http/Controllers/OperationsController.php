<?php

declare(strict_types=1);

namespace Lynomia\Modules\Admin\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lynomia\Modules\Admin\Http\Controllers\Concerns\ListsAcrossTenants;
use Lynomia\Modules\Audit\Application\Actions\RecordAuditEntry;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedReinstallState;
use Lynomia\Modules\Dedicated\Infrastructure\Models\DedicatedReinstall;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Vps\Domain\Enums\ReinstallState;
use Lynomia\Modules\Vps\Infrastructure\Models\VmReinstall;

/**
 * The destructive operations queue: rebuilds that are running, rebuilds that
 * stopped, and rebuilds nobody can tell the outcome of.
 *
 * Both reinstall lifecycles record everything an operator needs and neither
 * had a screen, which meant the platform's most dangerous operations were the
 * only ones a person could not see. A customer ringing to ask whether their
 * server was wiped was a question answerable only with `psql`.
 *
 * ---------------------------------------------------------------------------
 * One queue over two tables
 * ---------------------------------------------------------------------------
 *
 * A virtual machine's rebuild and a physical machine's rebuild share nothing
 * in their mechanics and everything in their consequences, so they are listed
 * together and separated by a `type`. The merge is bounded and says so: two
 * tables cannot be paginated as one without a union view, and a screen that
 * quietly showed the newest fifty of one kind while claiming to show
 * everything would be worse than one that admits its limit.
 *
 * ---------------------------------------------------------------------------
 * What an operator may decide
 * ---------------------------------------------------------------------------
 *
 * One verdict, on an operation that is waiting for one: this rebuild finished,
 * or it did not. Both are edges the state machines already have — the only
 * ways out of `needs_review` and `indeterminate` — and both require the
 * operator to say what they looked at, because the platform genuinely does not
 * know and is recording somebody's word for it.
 *
 * There is no "mark completed" for an operation that is still running, no way
 * to move one backwards, and nothing here writes to a hypervisor or a
 * controller. An operator who wants the machine rebuilt asks for a new
 * reinstall; retrying this one is not on offer, because a retry of a rebuild
 * is a second rebuild.
 */
final class OperationsController
{
    use ListsAcrossTenants;

    /** Rows read from each table before merging. */
    private const int MERGE_LIMIT = 200;

    private const string VPS = 'vps_reinstall';

    private const string DEDICATED = 'dedicated_reinstall';

    public function reinstalls(Request $request): JsonResponse
    {
        $type = $request->string('type')->value();
        $state = $request->string('state')->value();
        $customerId = $request->string('customer_id')->value();
        $onlyWaiting = $request->boolean('needs_attention');

        $rows = [];
        $truncated = false;

        if ($type === '' || $type === self::VPS) {
            $found = VmReinstall::query()
                ->when($state !== '', fn ($query) => $query->where('state', $state))
                ->when($customerId !== '', fn ($query) => $query->where('customer_id', $customerId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::MERGE_LIMIT)
                ->get();

            $truncated = $found->count() === self::MERGE_LIMIT;

            foreach ($found as $operation) {
                $rows[] = $this->vpsRow($operation);
            }
        }

        if ($type === '' || $type === self::DEDICATED) {
            $found = DedicatedReinstall::query()
                ->when($state !== '', fn ($query) => $query->where('state', $state))
                ->when($customerId !== '', fn ($query) => $query->where('customer_id', $customerId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(self::MERGE_LIMIT)
                ->get();

            $truncated = $truncated || $found->count() === self::MERGE_LIMIT;

            foreach ($found as $operation) {
                $rows[] = $this->dedicatedRow($operation);
            }
        }

        if ($onlyWaiting) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['needs_attention']));
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['requested_at'], (string) $a['requested_at']));

        $perPage = $this->perPage($request);
        $page = max(1, $request->integer('page', 1));
        $total = count($rows);

        return response()->json([
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'max_per_page' => self::MAX_PER_PAGE,
                /*
                 * Stated rather than hidden. Two tables cannot be paginated as
                 * one without a union view, so each is read to a bound and
                 * merged; `truncated` is how an operator knows the filters are
                 * now the only way to see the rest.
                 */
                'merge_limit' => self::MERGE_LIMIT,
                'truncated' => $truncated,
            ],
        ]);
    }

    /**
     * One operation, with everything that happened to it.
     *
     * The attempt log comes from the provisioning job and the decisions come
     * from the audit trail, so this is the whole history in the order it
     * happened: who asked for the rebuild, what the worker tried, what the
     * provider said, and what a person decided afterwards.
     */
    public function reinstall(string $type, string $operation): JsonResponse
    {
        $row = $this->find($type, $operation);

        $job = ProvisioningJob::query()
            ->with('attemptRecords')
            ->find($row['operation']->provisioning_job_id);

        // The machine, not the operation: the history worth reading is
        // everything that has been done to this server, including the rebuild
        // somebody asked for last month.
        $subjectId = (string) $row['row']['subject_id'];

        $audit = AuditEntry::query()
            ->where('subject_id', $subjectId)
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => [
                'operation' => $row['row'],
                'job' => $job === null ? null : [
                    'id' => $job->id,
                    'kind' => $job->kind->value,
                    'status' => $job->status->value,
                    'attempts' => $job->attempts,
                    'max_attempts' => $job->max_attempts,
                    'failure_class' => $job->failure_class?->value,
                    'last_error' => $job->last_error,
                    'remote_job_id' => $job->remote_job_id,
                    'attempt_log' => $job->attemptRecords
                        ->sortBy('created_at')
                        ->map(static fn ($attempt): array => [
                            'number' => $attempt->attempt_number,
                            'status' => $attempt->status->value,
                            'error_code' => $attempt->error_code,
                            'error_message' => $attempt->error_message,
                            'duration_ms' => $attempt->duration_ms,
                            'created_at' => $attempt->created_at->toIso8601String(),
                        ])->values()->all(),
                ],
                'audit' => $audit->map(static fn (AuditEntry $entry): array => [
                    'action' => $entry->action->value,
                    'actor' => $entry->actor_label,
                    'created_at' => $entry->created_at->toIso8601String(),
                ])->all(),
            ],
        ]);
    }

    /**
     * An operator's verdict on an operation the platform could not settle.
     *
     * @throws ValidationException
     */
    public function resolveReinstall(Request $request, string $type, string $operation): JsonResponse
    {
        $validated = $request->validate([
            'verdict' => ['required', 'string', 'in:completed,failed'],
            'evidence' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $found = $this->find($type, $operation);

        /** @var VmReinstall|DedicatedReinstall $model */
        $model = $found['operation'];

        if (! $model->state->needsAttention()) {
            throw ValidationException::withMessages([
                'verdict' => 'This operation is not waiting for a decision.',
            ]);
        }

        $completed = $validated['verdict'] === 'completed';

        if ($model instanceof VmReinstall) {
            $model->advanceTo($completed ? ReinstallState::Completed : ReinstallState::Failed);
        } else {
            $model->advanceTo($completed ? DedicatedReinstallState::Completed : DedicatedReinstallState::Failed);
        }

        $this->settleTheJob($model->provisioning_job_id, $completed);

        $user = $request->user();

        app(RecordAuditEntry::class)->execute(
            action: $completed ? AuditAction::ReinstallConfirmed : AuditAction::ReinstallAbandoned,
            subject: $model,
            customerId: $model->customer_id,
            context: [
                'type' => $type,
                'evidence' => $validated['evidence'],
                'previous_failure_code' => $model->failure_code,
                'provisioning_job_id' => $model->provisioning_job_id,
                'resolved_by' => $user instanceof User
                    ? sprintf('%s <%s>', $user->name, $user->email)
                    : 'system',
            ],
        );

        $this->tellTheCustomer($model, $completed, $type);

        return response()->json([
            'data' => [
                'id' => $model->getKey(),
                'type' => $type,
                'state' => $model->state->value,
            ],
        ]);
    }

    /**
     * Settle the job the operation belonged to, so one decision leaves one
     * outcome rather than a resolved rebuild beside a job still in review.
     */
    private function settleTheJob(string $jobId, bool $completed): void
    {
        $job = ProvisioningJob::query()->find($jobId);

        if ($job === null) {
            return;
        }

        $to = $completed ? ProvisioningJobStatus::Succeeded : ProvisioningJobStatus::Failed;

        if (! app(ProvisioningJobStateMachine::class)->canTransition($job->status, $to)) {
            // A job that already settled some other way. The operation's own
            // verdict stands; nothing here rewrites a finished job.
            return;
        }

        $job->status = $to;
        $job->finished_at = now();
        $job->next_attempt_at = null;
        $job->save();
    }

    /**
     * The customer hears the outcome, late but from the platform.
     *
     * A rebuild that sat in review for two hours is exactly the case where
     * somebody is waiting to be told, and an operator's verdict is the moment
     * the platform finally knows.
     */
    private function tellTheCustomer(Model $operation, bool $completed, string $type): void
    {
        $customerId = $operation->getAttribute('customer_id');

        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        app(NotifyCustomer::class)->execute(
            customerId: $customerId,
            type: $completed ? NotificationType::ReinstallCompleted : NotificationType::ReinstallFailed,
            idempotencyKey: 'reinstall-resolved:'.$type.':'.$operation->getKey(),
            subject: $operation,
            data: ['service' => 'your server', 'image' => ''],
            link: '/services',
        );
    }

    /**
     * @return array{operation: VmReinstall|DedicatedReinstall, row: array<string, mixed>}
     */
    private function find(string $type, string $operation): array
    {
        if ($type === self::VPS) {
            $found = VmReinstall::query()->findOrFail($operation);

            return ['operation' => $found, 'row' => $this->vpsRow($found)];
        }

        if ($type === self::DEDICATED) {
            $found = DedicatedReinstall::query()->findOrFail($operation);

            return ['operation' => $found, 'row' => $this->dedicatedRow($found)];
        }

        throw ValidationException::withMessages([
            'type' => 'There is no operation of that type.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function vpsRow(VmReinstall $operation): array
    {
        return [
            'id' => $operation->id,
            'type' => self::VPS,
            'state' => $operation->state->value,
            'needs_attention' => $operation->state->needsAttention(),
            'in_flight' => ! $operation->state->isTerminal(),
            // The fact a customer rings about, answered from the timestamp
            // rather than inferred from the state.
            'data_destroyed' => $operation->destroyedData(),
            'customer_id' => $operation->customer_id,
            'service_id' => $operation->service_id,
            'subject_type' => 'virtual_machine',
            'subject_id' => $operation->virtual_machine_id,
            'provider_resource_id' => $operation->provider_resource_id,
            'provider_node' => $operation->provider_node,
            'provisioning_job_id' => $operation->provisioning_job_id,
            'failure_code' => $operation->failure_code,
            'failure_message' => $operation->failure_message,
            'requested_at' => $operation->created_at->toIso8601String(),
            'state_changed_at' => $operation->state_changed_at?->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dedicatedRow(DedicatedReinstall $operation): array
    {
        return [
            'id' => $operation->id,
            'type' => self::DEDICATED,
            'state' => $operation->state->value,
            'needs_attention' => $operation->state->needsAttention(),
            'in_flight' => ! $operation->state->isTerminal(),
            'data_destroyed' => $operation->destroyedData(),
            'customer_id' => $operation->customer_id,
            'service_id' => $operation->service_id,
            'subject_type' => 'dedicated_server',
            'subject_id' => $operation->dedicated_server_id,
            'provider_resource_id' => null,
            'provider_node' => null,
            'provisioning_job_id' => $operation->provisioning_job_id,
            'failure_code' => $operation->failure_code,
            'failure_message' => $operation->failure_message,
            'requested_at' => $operation->created_at->toIso8601String(),
            'state_changed_at' => $operation->state_changed_at?->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
        ];
    }
}
