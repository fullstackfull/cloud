<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\ReconcileBackup;
use Lynomia\Modules\Backups\Application\Actions\ReconcileBackupInventory;
use Lynomia\Modules\Backups\Application\Actions\ReconcileRunningBackups;
use Lynomia\Modules\Backups\Application\Actions\RestoreServiceBackup;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backups\Doubles\SelfVerifyingDatastore;
use Tests\Feature\Backups\Doubles\TwoTaskDatastore;
use Tests\Feature\Backups\Doubles\UnreachableBackupProvider;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * The two outcomes the customer was never told about.
 *
 * The previous phase wired the four terminal backup and restore messages and
 * left two holes open on purpose, because closing them meant inventing
 * vocabulary:
 *
 *  - a whole-machine restore that reaches `NeedsReview` — the provider stopped
 *    answering, or the task outlived the tracking window — announced nothing.
 *    That is the most dangerous silence in this module: a restore that may be
 *    writing to the customer's disks right now, and the customer with no
 *    reason not to press the button again.
 *  - a verification that came back saying the archive could not be read
 *    announced nothing either. The verdict sat in `verified` for an operator
 *    alert, and the customer went on believing they had a backup.
 *
 * Both now have their own type, and the point of every test here is that
 * neither borrows one of the four that already existed. "We could not confirm"
 * is not "it failed", and it is certainly not "it completed".
 *
 * ---------------------------------------------------------------------------
 * What must NOT raise BackupVerificationFailed
 * ---------------------------------------------------------------------------
 *
 * Only a verdict counts. A datastore that timed out, refused, or has not been
 * asked yet has said nothing about the archive, and `verified` stays null —
 * which is the whole reason that column is three-valued. Telling a customer
 * their backup failed integrity checking because a provider was briefly
 * unreachable would be the same collapse of null into false that F-10 closed,
 * arriving by email this time.
 */
final class TheOutcomesNobodyWasToldAboutTest extends VpsApiTestCase
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

    // ---- 1. a restore nobody can settle says exactly that -----------------

    #[Test]
    public function a_restore_that_outlives_the_tracking_window_says_it_needs_review(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        // Still running, and running is all the provider will ever say.
        $this->datastore($machine, [self::RESTORE_TASK => $this->running()]);

        $this->travel(config('backups.max_poll_hours', 12) + 1)->hours();

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(BackupState::NeedsReview, $backup->refresh()->state);

        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreNeedsReview));
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreCompleted));
    }

    #[Test]
    public function a_restore_the_provider_would_not_start_says_it_needs_review(): void
    {
        /*
         * The case RestoreServiceBackup's own docblock dwells on: the call did
         * not answer, so the restore may be running right now. The row stops
         * at NeedsReview and never becomes pollable, so if this class does not
         * announce it, nothing ever will.
         */
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        app(BackupProviderFactory::class)->swap($machine->cluster()->firstOrFail(), new UnreachableBackupProvider);

        app(RestoreServiceBackup::class)->execute($backup, $machine, $machine->hostname);

        $this->assertSame(BackupState::NeedsReview, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreNeedsReview));
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreFailed));
    }

    // ---- 2. and says it once ----------------------------------------------

    #[Test]
    public function reconciling_the_same_unsettleable_restore_does_not_repeat_itself(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $this->datastore($machine, [self::RESTORE_TASK => $this->running()]);
        $this->travel(config('backups.max_poll_hours', 12) + 1)->hours();

        app(ReconcileBackup::class)->execute($backup);

        for ($i = 0; $i < 10; $i++) {
            app(ReconcileBackup::class)->execute($backup->refresh());
            app(ReconcileRunningBackups::class)->execute();
        }

        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreNeedsReview));
    }

    // ---- 3. a later restore is its own event ------------------------------

    #[Test]
    public function a_second_restore_that_needs_review_gets_its_own_word(): void
    {
        /*
         * The first restore finished, so the row is `Restored` and can be
         * restored from again. The second attempt is a different provider task
         * and a different event, and a key scoped only to the row would
         * swallow it.
         *
         * Note which way round this has to be: a restore that has *already*
         * reached NeedsReview is terminal — `allowedNext()` gives it nowhere
         * to go — so the first of the two cannot be the one needing review.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $datastore = $this->datastore($machine, [
            self::RESTORE_TASK => $this->finished(successful: true),
        ]);

        app(ReconcileBackup::class)->execute($backup);
        $this->assertSame(BackupState::Restored, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted));

        $second = 'UPID:pve-01:restore-0002';
        $datastore->settle($second, $this->running());

        $backup->refresh()->transitionTo(BackupState::Restoring, [
            'restore_task_id' => $second,
            'restore_started_at' => now(),
        ]);

        $this->travel(config('backups.max_poll_hours', 12) + 1)->hours();
        app(ReconcileBackup::class)->execute($backup->refresh());

        $this->assertSame(BackupState::NeedsReview, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreNeedsReview));
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted));
    }

    // ---- 4. a verdict of unreadable is told to the customer ---------------

    #[Test]
    public function a_verification_that_came_back_unreadable_tells_the_customer(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->verifyingBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::VERIFICATION_TASK => $this->finished(successful: false, exitStatus: 'chunk 41f2 could not be read'),
        ]);

        app(ReconcileBackup::class)->execute($backup);

        $this->assertFalse($backup->refresh()->verified);
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupVerificationFailed));

        // And it is not dressed as the backup having failed. The backup
        // succeeded; the archive it wrote cannot be read, which is a different
        // and worse sentence.
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupFailed));
    }

    #[Test]
    public function a_datastore_that_reports_a_bad_archive_in_its_listing_tells_the_customer(): void
    {
        /*
         * The other writer of the same verdict. Proxmox Backup Server verifies
         * on its own schedule and reports the result in a listing rather than
         * against a task, so an archive can be declared unreadable without any
         * verification this platform started.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        app(BackupProviderFactory::class)->swap(
            $machine->cluster()->firstOrFail(),
            new SelfVerifyingDatastore([(string) $backup->archive_id => false]),
        );

        app(ReconcileBackupInventory::class)->execute();

        $this->assertFalse($backup->refresh()->verified);
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupVerificationFailed));
    }

    // ---- 5. once, however many times it is observed -----------------------

    #[Test]
    public function the_same_unreadable_verdict_is_only_announced_once(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        app(BackupProviderFactory::class)->swap(
            $machine->cluster()->firstOrFail(),
            new SelfVerifyingDatastore([(string) $backup->archive_id => false]),
        );

        for ($i = 0; $i < 10; $i++) {
            app(ReconcileBackupInventory::class)->execute();
        }

        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupVerificationFailed));
    }

    // ---- 6-8. everything that is not a verdict stays quiet ----------------

    #[Test]
    public function an_archive_nobody_has_checked_is_never_reported_as_failing(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        // The datastore lists it and offers no verdict at all.
        app(BackupProviderFactory::class)->swap(
            $machine->cluster()->firstOrFail(),
            new SelfVerifyingDatastore([(string) $backup->archive_id => null]),
        );

        app(ReconcileBackupInventory::class)->execute();

        $this->assertNull($backup->refresh()->verified);
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupVerificationFailed));
    }

    #[Test]
    public function a_datastore_that_will_not_answer_is_never_reported_as_a_bad_archive(): void
    {
        /*
         * A timeout says nothing about the archive. This is the same collapse
         * F-10 closed on the screen, and it must not arrive by email either.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->verifyingBackupFor($customer, $machine);

        app(BackupProviderFactory::class)->swap($machine->cluster()->firstOrFail(), new UnreachableBackupProvider);

        app(ReconcileBackup::class)->execute($backup);
        app(ReconcileBackupInventory::class)->execute();

        $this->assertNull($backup->refresh()->verified, 'An unanswered read is not a verdict.');
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupVerificationFailed));
    }

    #[Test]
    public function an_archive_that_read_back_cleanly_is_never_reported_as_failing(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->verifyingBackupFor($customer, $machine);

        $this->datastore($machine, [self::VERIFICATION_TASK => $this->finished(successful: true)]);

        app(ReconcileBackup::class)->execute($backup);

        $this->assertTrue($backup->refresh()->verified);
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupVerificationFailed));
    }

    // ---- 9. neither reaches anybody else ----------------------------------

    #[Test]
    public function neither_message_reaches_another_customer(): void
    {
        [$alice] = $this->accountWithOwner();
        [$bob] = $this->accountWithOwner();

        $machine = $this->machineFor($alice);
        $restoring = $this->restoringBackupFor($alice, $machine);
        $verifying = $this->verifyingBackupFor($alice, $machine);

        $this->datastore($machine, [
            self::RESTORE_TASK => $this->running(),
            self::VERIFICATION_TASK => $this->finished(successful: false, exitStatus: 'unreadable'),
        ]);

        app(ReconcileBackup::class)->execute($verifying);
        $this->travel(config('backups.max_poll_hours', 12) + 1)->hours();
        app(ReconcileBackup::class)->execute($restoring);

        $this->assertSame(1, $this->notifications($alice, NotificationType::RestoreNeedsReview));
        $this->assertSame(1, $this->notifications($alice, NotificationType::BackupVerificationFailed));
        $this->assertSame(
            0,
            Notification::query()->where('customer_id', $bob->getKey())->count(),
            "Another customer's archive is none of Bob's business.",
        );
    }

    // ---- 10. the four that already worked still do ------------------------

    #[Test]
    public function the_four_existing_outcomes_are_unchanged(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $finished = $this->runningBackupFor($customer, $machine, self::BACKUP_TASK);
        $this->datastore($machine, [self::BACKUP_TASK => $this->finished(successful: true)]);
        app(ReconcileBackup::class)->execute($finished);

        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupCompleted));
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreNeedsReview));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupVerificationFailed));

        $restoring = $this->restoringBackupFor($customer, $machine);
        $this->datastore($machine, [self::RESTORE_TASK => $this->finished(successful: true)]);
        app(ReconcileBackup::class)->execute($restoring);

        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted));
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreNeedsReview));
    }

    // ---- fixtures ---------------------------------------------------------

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

    private function completedBackupFor(Customer $customer, VirtualMachine $machine): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster()->firstOrFail()->getKey(),
            'provider_task_id' => self::BACKUP_TASK.'-c',
            'verified' => null,
        ]);
    }

    private function runningBackupFor(Customer $customer, VirtualMachine $machine, string $taskId): Backup
    {
        return Backup::factory()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster()->firstOrFail()->getKey(),
            'state' => BackupState::Running,
            'provider_task_id' => $taskId,
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
