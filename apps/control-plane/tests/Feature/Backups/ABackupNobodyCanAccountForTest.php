<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\ReconcileBackup;
use Lynomia\Modules\Backups\Application\Actions\ReconcileRunningBackups;
use Lynomia\Modules\Backups\Application\Actions\RequestServiceBackup;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backups\Doubles\TwoTaskDatastore;
use Tests\Feature\Backups\Doubles\UnreachableBackupProvider;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * The last outcome in this module with no sentence of its own.
 *
 * A backup that reaches `NeedsReview` is one the platform cannot account for:
 * the task outlived the tracking window, or the provider stopped recognising
 * it, or the cluster row went away before the answer came back. The archive
 * may be sitting on a datastore taking up space, or may not exist at all, and
 * only a person looking at the datastore settles it.
 *
 * Until now the customer was told nothing. That silence was deliberate and
 * recorded: the previous phase routed every quarantine through one
 * announcement and put an explicit guard on this branch, because without one
 * it would have started saying `BackupFailed` — which is worse than silence,
 * since nothing failed and nobody knows.
 *
 * ---------------------------------------------------------------------------
 * Why the key is the row and nothing else
 * ---------------------------------------------------------------------------
 *
 * Two facts from the model, both asserted below rather than assumed:
 * `NeedsReview` is terminal — `allowedNext()` gives it nowhere to go — and
 * every backup run creates its own row. So one row reaches this outcome at
 * most once in its life, and `backup:{id}:needs_review` cannot collide with a
 * second genuine run.
 *
 * Scoping it to the provider task would be worse, not stronger. A verification
 * overwrites `provider_task_id`, so it is not stable for the life of the row —
 * and the request-side route has no task id at all, which is precisely what
 * makes it indeterminate.
 */
final class ABackupNobodyCanAccountForTest extends VpsApiTestCase
{
    private const BACKUP_TASK = 'UPID:pve-01:backup-0001';

    private const RESTORE_TASK = 'UPID:pve-01:restore-9999';

    private const VERIFICATION_TASK = 'UPID:pve-01:verify-5555';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        $this->app->singleton(BackupProviderFactory::class);
    }

    // ---- 1. a backup nobody can settle says exactly that ------------------

    #[Test]
    public function a_backup_that_outlives_the_tracking_window_says_it_needs_review(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->runningBackupFor($customer, $machine);

        // Running, and running is all the provider will ever say about it.
        $this->datastore($machine, [self::BACKUP_TASK => $this->running()]);

        $this->travel($this->window() + 1)->hours();

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(BackupState::NeedsReview, $backup->refresh()->state);

        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupNeedsReview));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupCompleted));
    }

    #[Test]
    public function a_backup_the_provider_would_not_start_says_it_needs_review(): void
    {
        /*
         * The route the poller can never see. An indeterminate startBackup
         * leaves a row with no task id, so `isAwaitingProvider()` is false and
         * no sweep will ever pick it up — if this is not announced where it
         * happens, it is never announced at all.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $cluster = $machine->cluster()->firstOrFail();
        // The request path resolves a datastore before it calls anything; the
        // poller paths never do, which is why only this test needs it.
        config()->set('backups.datastores.'.($cluster->credentials_reference ?? $cluster->slug), 'pbs-test-01');

        app(BackupProviderFactory::class)->swap($cluster, new UnreachableBackupProvider);

        $backup = app(RequestServiceBackup::class)->execute($machine);

        $this->assertSame(BackupState::NeedsReview, $backup->refresh()->state);
        $this->assertNull($backup->provider_task_id);
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupNeedsReview));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupFailed));
    }

    // ---- 2. and says it once ----------------------------------------------

    #[Test]
    public function reconciling_the_same_unaccountable_backup_does_not_repeat_itself(): void
    {
        /*
         * The model facts the key rests on, asserted rather than assumed. If
         * either stopped being true, a row-scoped key would be the wrong
         * choice and this test is where that should be noticed.
         */
        $this->assertSame(
            [],
            BackupState::NeedsReview->allowedNext(),
            'NeedsReview is terminal, which is what makes one row one occurrence.',
        );

        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->runningBackupFor($customer, $machine);

        $this->datastore($machine, [self::BACKUP_TASK => $this->running()]);
        $this->travel($this->window() + 1)->hours();

        app(ReconcileBackup::class)->execute($backup);

        for ($i = 0; $i < 10; $i++) {
            app(ReconcileBackup::class)->execute($backup->refresh());
            app(ReconcileRunningBackups::class)->execute();
        }

        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupNeedsReview));
    }

    // ---- 3. nothing else borrows the word ---------------------------------

    #[Test]
    public function a_backup_that_succeeded_is_never_said_to_need_review(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->runningBackupFor($customer, $machine);

        $this->datastore($machine, [self::BACKUP_TASK => $this->finished(successful: true)]);

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupCompleted));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupNeedsReview));
    }

    #[Test]
    public function a_backup_that_failed_is_said_to_have_failed_and_nothing_else(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->runningBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::BACKUP_TASK => $this->finished(successful: false, exitStatus: 'no space left on datastore'),
        ]);

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(BackupState::Failed, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupNeedsReview));
    }

    #[Test]
    public function an_archive_that_would_not_read_back_is_not_a_backup_needing_review(): void
    {
        /*
         * A verification verdict is knowledge, not the absence of it. The
         * backup's own outcome was settled long ago and is not in question.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->verifyingBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::VERIFICATION_TASK => $this->finished(successful: false, exitStatus: 'chunk 41f2 could not be read'),
        ]);

        app(ReconcileBackup::class)->execute($backup);

        $this->assertFalse($backup->refresh()->verified);
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupVerificationFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupNeedsReview));
    }

    #[Test]
    public function a_restore_that_needs_review_is_not_a_backup_that_needs_review(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $this->datastore($machine, [self::RESTORE_TASK => $this->running()]);
        $this->travel($this->window() + 1)->hours();

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(BackupState::NeedsReview, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreNeedsReview));
        $this->assertSame(
            0,
            $this->notifications($customer, NotificationType::BackupNeedsReview),
            'One row, two operations; the message names the one that was running.',
        );
    }

    // ---- the timing boundary ----------------------------------------------

    #[Test]
    public function a_backup_still_inside_the_polling_window_is_told_nothing(): void
    {
        /*
         * Not knowing yet is the normal condition of a poller, and it is not
         * news. A message raised on an ordinary indeterminate poll would reach
         * the customer minutes after they pressed the button, about a backup
         * that is very likely running perfectly well.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->runningBackupFor($customer, $machine);

        $this->datastore($machine, [self::BACKUP_TASK => $this->running()]);

        for ($i = 0; $i < 5; $i++) {
            $this->travel(30)->minutes();
            app(ReconcileBackup::class)->execute($backup->refresh());
        }

        $this->assertSame(BackupState::Running, $backup->refresh()->state, 'Still inside the window.');
        $this->assertSame(0, Notification::query()->where('customer_id', $customer->getKey())->count());
    }

    // ---- 7. and it reaches nobody else ------------------------------------

    #[Test]
    public function a_backup_needing_review_never_reaches_another_customer(): void
    {
        [$alice] = $this->accountWithOwner();
        [$bob] = $this->accountWithOwner();

        $machine = $this->machineFor($alice);
        $backup = $this->runningBackupFor($alice, $machine);

        $this->datastore($machine, [self::BACKUP_TASK => $this->running()]);
        $this->travel($this->window() + 1)->hours();

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(1, $this->notifications($alice, NotificationType::BackupNeedsReview));
        $this->assertSame(
            0,
            Notification::query()->where('customer_id', $bob->getKey())->count(),
            "Another customer's backup is none of Bob's business.",
        );
    }

    // ---- 8. the six that already worked still do --------------------------

    #[Test]
    public function the_six_existing_outcomes_are_unchanged(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $done = $this->runningBackupFor($customer, $machine);
        $this->datastore($machine, [self::BACKUP_TASK => $this->finished(successful: true)]);
        app(ReconcileBackup::class)->execute($done);

        $restored = $this->restoringBackupFor($customer, $machine);
        $this->datastore($machine, [self::RESTORE_TASK => $this->finished(successful: true)]);
        app(ReconcileBackup::class)->execute($restored);

        $unreadable = $this->verifyingBackupFor($customer, $machine);
        $this->datastore($machine, [self::VERIFICATION_TASK => $this->finished(successful: false, exitStatus: 'bad chunk')]);
        app(ReconcileBackup::class)->execute($unreadable);

        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupCompleted));
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted));
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupVerificationFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupNeedsReview));
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreNeedsReview));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreFailed));
    }

    // ---- fixtures ---------------------------------------------------------

    private function window(): int
    {
        return max(1, (int) config('backups.max_poll_hours', 12));
    }

    /**
     * @param  array<string, BackupTaskState>  $tasks
     */
    private function datastore(VirtualMachine $machine, array $tasks): TwoTaskDatastore
    {
        $datastore = new TwoTaskDatastore($tasks, nextRestoreTaskId: self::RESTORE_TASK);

        app(BackupProviderFactory::class)->swap($machine->cluster()->firstOrFail(), $datastore);

        return $datastore;
    }

    private function finished(bool $successful, ?string $exitStatus = null): BackupTaskState
    {
        return new BackupTaskState(
            taskId: 'irrelevant',
            finished: true,
            successful: $successful,
            exitStatus: $exitStatus,
            archiveId: 'vm/101/2026-09-20T00:00:00Z',
        );
    }

    private function running(): BackupTaskState
    {
        return new BackupTaskState(taskId: 'irrelevant', finished: false, successful: false);
    }

    private function runningBackupFor(Customer $customer, VirtualMachine $machine): Backup
    {
        return Backup::factory()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster()->firstOrFail()->getKey(),
            'state' => BackupState::Running,
            'provider_task_id' => self::BACKUP_TASK,
            'started_at' => now(),
        ]);
    }

    private function restoringBackupFor(Customer $customer, VirtualMachine $machine): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster()->firstOrFail()->getKey(),
            'provider_task_id' => self::BACKUP_TASK.'-r',
            'state' => BackupState::Restoring,
            'restore_task_id' => self::RESTORE_TASK,
            'restore_started_at' => now(),
            'started_at' => now(),
        ]);
    }

    private function verifyingBackupFor(Customer $customer, VirtualMachine $machine): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster()->firstOrFail()->getKey(),
            'state' => BackupState::Verifying,
            'provider_task_id' => self::VERIFICATION_TASK,
            'verification_task_id' => self::VERIFICATION_TASK,
            'started_at' => now(),
        ]);
    }

    private function notifications(Customer $customer, NotificationType $type): int
    {
        return Notification::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', $type->value)
            ->count();
    }
}
