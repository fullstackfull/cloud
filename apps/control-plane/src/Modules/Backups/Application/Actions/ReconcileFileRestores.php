<?php

declare(strict_types=1);

namespace Lynomia\Modules\Backups\Application\Actions;

use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Backups\Application\DTOs\ReconciliationSweep;
use Lynomia\Modules\Backups\Domain\Enums\FileRestoreState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupProviderException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileRestore;
use Lynomia\Modules\Notifications\Application\Actions\NotifyCustomer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use Throwable;

/**
 * Follow the file restores the provider is still running, and tell the
 * customer how each one ended.
 *
 * The same poller shape as a backup's: a provider that cannot be asked is
 * asked again later; one that says the task is gone, or that has not
 * finished after the deadline, leaves the row in needs_review for a person.
 * Nothing here restarts a restore.
 */
final readonly class ReconcileFileRestores
{
    public function __construct(
        private BackupProviderFactory $providers,
        private SecretRedactor $redactor,
        private NotifyCustomer $notify,
    ) {}

    public function execute(int $limit = 200): ReconciliationSweep
    {
        $settled = 0;
        $failed = 0;

        $awaiting = BackupFileRestore::query()
            ->awaitingProvider()
            ->orderByRaw('last_polled_at nulls first')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($awaiting as $restore) {
            try {
                $before = $restore->state;
                $after = $this->reconcile($restore);

                if ($after->state !== $before) {
                    $settled++;
                }
            } catch (Throwable $e) {
                $failed++;

                Log::error('A file restore could not be reconciled with its provider.', [
                    'file_restore_id' => $restore->getKey(),
                    'exception' => $e::class,
                ]);
            }
        }

        return new ReconciliationSweep(considered: $awaiting->count(), settled: $settled, failed: $failed);
    }

    private function reconcile(BackupFileRestore $restore): BackupFileRestore
    {
        /** @var Backup $backup */
        $backup = $restore->backup()->firstOrFail();
        $cluster = $backup->cluster()->first();

        if ($cluster === null) {
            return $this->settle($restore, FileRestoreState::NeedsReview, 'the cluster this backup was taken on no longer exists in the platform');
        }

        $provider = $this->providers->for($cluster);

        try {
            $state = $provider->taskState($restore->node_name, (string) $restore->provider_task_id);
        } catch (BackupProviderException $e) {
            $restore->forceFill(['last_polled_at' => now(), 'poll_count' => $restore->poll_count + 1])->save();

            if ($e->isIndeterminate()) {
                return $this->giveUpIfOverdue($restore);
            }

            return $this->settle($restore, FileRestoreState::NeedsReview, $this->redactor->redactString($e->getMessage()));
        }

        $restore->forceFill(['last_polled_at' => now(), 'poll_count' => $restore->poll_count + 1])->save();

        if ($state->isRunning()) {
            return $this->giveUpIfOverdue($restore);
        }

        if ($state->hasFailed()) {
            return $this->settle(
                $restore,
                FileRestoreState::Failed,
                $this->redactor->redactString($state->exitStatus ?? 'the provider reported the task as failed'),
            );
        }

        return $this->settle($restore, FileRestoreState::Succeeded, null);
    }

    private function giveUpIfOverdue(BackupFileRestore $restore): BackupFileRestore
    {
        $limit = max(1, (int) config('backups.max_poll_hours', 12));
        $startedAt = $restore->started_at ?? $restore->created_at;

        if ($startedAt->addHours($limit)->isFuture()) {
            return $restore;
        }

        return $this->settle($restore, FileRestoreState::NeedsReview, sprintf(
            'the provider task was still unfinished after %d hours; the platform has stopped tracking it',
            $limit,
        ));
    }

    private function settle(BackupFileRestore $restore, FileRestoreState $state, ?string $reason): BackupFileRestore
    {
        $restore->forceFill([
            'state' => $state,
            'failure_reason' => $reason,
            'finished_at' => $state === FileRestoreState::NeedsReview ? null : now(),
        ])->save();

        $type = match ($state) {
            FileRestoreState::Succeeded => NotificationType::FileRestoreCompleted,
            FileRestoreState::Failed => NotificationType::FileRestoreFailed,
            default => NotificationType::FileRestoreNeedsReview,
        };

        /** @var Service $service */
        $service = $restore->service()->firstOrFail();
        $label = $service->label;

        $this->notify->execute(
            customerId: $restore->customer_id,
            type: $type,
            idempotencyKey: sprintf('file-restore:%s:%s', $restore->getKey(), $state->value),
            subject: $restore,
            data: [
                'service' => is_string($label) && $label !== '' ? $label : (string) $service->getKey(),
                'count' => $restore->path_count,
            ],
            link: '/backups',
        );

        return $restore->refresh();
    }
}
