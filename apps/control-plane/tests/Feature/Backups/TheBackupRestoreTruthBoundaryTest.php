<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\ReconcileBackup;
use Lynomia\Modules\Backups\Application\Actions\ReconcileRunningBackups;
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
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * A restore is finished when the RESTORE finished, and never before.
 *
 * ---------------------------------------------------------------------------
 * The defect these tests exist to make impossible
 * ---------------------------------------------------------------------------
 *
 * `RestoreServiceBackup` puts the restore's task handle in `restore_task_id`,
 * deliberately and with a docblock explaining why it must not go in
 * `provider_task_id`: that column holds the identifier of the backup itself,
 * which is the one thing that finds the archive when a restore goes wrong.
 *
 * `ReconcileBackup` then polls `provider_task_id` for every row in flight. For
 * a row in `Restoring` that is the backup's own creation task — which finished
 * successfully hours or days ago — so the first sweep after a restore starts
 * reads "OK", takes the `Restoring` branch of `successStateFor`, and writes
 * `Restored`. The customer is told their machine is back while the disks are
 * still being written, and would be told the same thing if the restore had
 * failed outright.
 *
 * `restored_at` is a column on the table, cast on the model, created by its
 * own migration, and written by nothing at all.
 *
 * ---------------------------------------------------------------------------
 * The oracle is not the simulator
 * ---------------------------------------------------------------------------
 *
 * Every test here builds two provider tasks with two different identifiers and
 * two different outcomes, through {@see TwoTaskDatastore}, which throws for a
 * handle it never issued. Polling the wrong column is therefore not a
 * plausible-looking wrong answer; it is an error. That is deliberate: a fake
 * that answered the same thing for every task id would mirror the bug and make
 * it untestable, which Round 1 found had already happened elsewhere in this
 * repository.
 */
final class TheBackupRestoreTruthBoundaryTest extends VpsApiTestCase
{
    private const BACKUP_TASK = 'UPID:pve-01:backup-0001';

    private const RESTORE_TASK = 'UPID:pve-01:restore-9999';

    private const VERIFICATION_TASK = 'UPID:pve-01:verify-5555';

    private int $backupsMade = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        $this->app->singleton(BackupProviderFactory::class);
    }

    // ---- 1. a running restore is not a finished one -----------------------

    #[Test]
    public function a_restore_still_running_is_not_reported_as_completed(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $this->datastore($machine, [
            // The backup's own task, long since finished and fine. This is the
            // answer the platform used to accept as proof the restore was over.
            self::BACKUP_TASK => $this->finished(successful: true),
            self::RESTORE_TASK => $this->running(),
        ]);

        app(ReconcileBackup::class)->execute($backup);

        $settled = $backup->refresh();

        $this->assertSame(
            BackupState::Restoring,
            $settled->state,
            'A restore that is still running must not be reported as completed.'
        );
        $this->assertNull($settled->restored_at, 'Nothing has been restored yet.');
    }

    // ---- 2. a failed restore reports failure -------------------------------

    #[Test]
    public function a_failed_restore_is_reported_as_a_failure(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::BACKUP_TASK => $this->finished(successful: true),
            self::RESTORE_TASK => $this->finished(successful: false, exitStatus: 'target volume is read-only'),
        ]);

        app(ReconcileBackup::class)->execute($backup);

        $settled = $backup->refresh();

        // The backup itself survives a failed restore, so the row goes back to
        // Succeeded — but with the reason, and without a restore timestamp.
        $this->assertSame(BackupState::Succeeded, $settled->state);
        $this->assertNotNull($settled->failure_reason);
        $this->assertNull($settled->restored_at, 'A restore that failed did not restore anything.');
    }

    // ---- 3. a real success writes the timestamp, once ---------------------

    #[Test]
    public function a_completed_restore_writes_restored_at(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::BACKUP_TASK => $this->finished(successful: true),
            self::RESTORE_TASK => $this->finished(successful: true),
        ]);

        app(ReconcileBackup::class)->execute($backup);

        $settled = $backup->refresh();

        $this->assertSame(BackupState::Restored, $settled->state);
        $this->assertNotNull($settled->restored_at, 'A confirmed restore records when it finished.');
    }

    // ---- 4. the timestamp is not rewritten on every poll ------------------

    #[Test]
    public function repeated_reconciliation_leaves_restored_at_alone(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::BACKUP_TASK => $this->finished(successful: true),
            self::RESTORE_TASK => $this->finished(successful: true),
        ]);

        app(ReconcileBackup::class)->execute($backup);
        $first = $backup->refresh()->restored_at;

        $this->travel(2)->hours();

        for ($i = 0; $i < 5; $i++) {
            app(ReconcileRunningBackups::class)->execute();
        }

        $this->assertNotNull($first);
        $this->assertTrue(
            $first->equalTo($backup->refresh()->restored_at),
            'The moment a restore finished must not move every time a sweep runs.'
        );
    }

    // ---- 4b. and it does not move when the backup was taken ---------------

    #[Test]
    public function a_restore_does_not_overwrite_when_the_backup_was_taken(): void
    {
        /*
         * `finished_at` is when the archive was written, and the portal shows
         * it in a column headed "Taken". The success transition stamped it for
         * whatever operation had just finished, so a restore — and a
         * verification, which runs against every archive on a sweep — moved a
         * customer's record of when their backup was taken to today.
         *
         * Latent until now, because a restore never reached that branch with
         * its own task: this is the second defect the wrong task id was
         * hiding.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $takenAt = $backup->finished_at;
        $this->assertNotNull($takenAt);

        $this->datastore($machine, [
            self::BACKUP_TASK => $this->finished(successful: true),
            self::RESTORE_TASK => $this->finished(successful: true),
        ]);

        $this->travel(3)->days();
        app(ReconcileBackup::class)->execute($backup);

        $settled = $backup->refresh();

        $this->assertTrue(
            $takenAt->equalTo($settled->finished_at),
            'A restore says nothing about when the backup was taken.'
        );
        $this->assertNotNull($settled->restored_at);
        $this->assertFalse(
            $takenAt->equalTo($settled->restored_at),
            'The two are different facts and must not collapse onto one column.'
        );
    }

    // ---- 5. corrupt is not the same as unchecked --------------------------

    #[Test]
    public function a_corrupt_archive_reads_differently_from_an_unchecked_one(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $corrupt = $this->completedBackupFor($customer, $machine, verified: false);
        $unchecked = $this->completedBackupFor($customer, $machine, verified: null);

        $rows = collect(
            $this->actingAs($user)
                ->getJson('/api/v1/vps/'.$machine->id.'/backups')
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $this->assertFalse($rows[$corrupt->id]['verified'], 'A read-back failure is false, never null.');
        $this->assertNull($rows[$unchecked->id]['verified'], 'Nobody has looked at this one.');

        // And the difference has to reach the thing a customer acts on. A
        // confirmed-corrupt archive offering a Restore button is the defect.
        $this->assertFalse(
            $rows[$corrupt->id]['is_restorable'],
            'An archive the datastore could not read back must not be offered for restore.'
        );
        $this->assertTrue($rows[$unchecked->id]['is_restorable']);
    }

    // ---- 5b. and the verdict that makes it corrupt is recorded as false ----

    #[Test]
    public function a_verification_that_failed_records_false_and_not_null(): void
    {
        /*
         * The write side of the same rule, which no test pinned until this
         * one: a deliberate collapse of `false` into `null` here passed the
         * entire Backups suite. ReconcileBackup's own docblock is explicit
         * that the three values are not interchangeable — "an archive that was
         * checked and found unreadable looked exactly like one nobody had got
         * around to checking" — and that sentence was load-bearing prose with
         * nothing holding it up.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->verifyingBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::VERIFICATION_TASK => $this->finished(successful: false, exitStatus: 'chunk 41f2 could not be read'),
        ]);

        app(ReconcileBackup::class)->execute($backup);

        $settled = $backup->refresh();

        $this->assertSame(BackupState::Failed, $settled->state);
        $this->assertFalse($settled->verified, 'Checked and unreadable is false; it is not "nobody looked".');
        $this->assertNull($settled->verified_at, 'There is no moment at which this archive was known good.');

        /*
         * And a failed read-back is not a failed backup, so it borrows neither
         * of the backup's words. The verdict is on the row for the operator
         * alert to find; this vocabulary has no truthful customer-facing type
         * for it, and inventing one is not this phase's business.
         */
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupCompleted));
    }

    // ---- 6. the server refuses, not just the screen -----------------------

    #[Test]
    public function the_server_refuses_to_restore_a_confirmed_corrupt_archive(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $corrupt = $this->completedBackupFor($customer, $machine, verified: false);

        $this->actingAs($user)
            ->postJson(
                '/api/v1/vps/'.$machine->id.'/backups/'.$corrupt->id.'/restore',
                ['confirmation' => $machine->hostname],
            )
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'backup.not_restorable');

        $this->assertSame(BackupState::Succeeded, $corrupt->refresh()->state);
        $this->assertNull($corrupt->refresh()->restore_task_id);
    }

    // ---- 7. unchecked follows the documented policy -----------------------

    #[Test]
    public function an_unchecked_archive_is_still_restorable(): void
    {
        /*
         * The existing product contract, stated on BackupState::isRestorable:
         * "a customer facing a lost machine would rather try an unverified
         * backup than be told no". Null is not corrupt, and this test exists
         * so that a fix for the corrupt case cannot quietly take the
         * unverified one with it.
         */
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $unchecked = $this->completedBackupFor($customer, $machine, verified: null);

        $this->datastore($machine, [(string) $unchecked->provider_task_id => $this->finished(successful: true)]);

        $this->actingAs($user)
            ->postJson(
                '/api/v1/vps/'.$machine->id.'/backups/'.$unchecked->id.'/restore',
                ['confirmation' => $machine->hostname],
            )
            ->assertStatus(202);
    }

    // ---- 8-9. the backup's own outcome is announced -----------------------

    #[Test]
    public function a_finished_backup_tells_the_customer_once(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->runningBackupFor($customer, $machine);

        $this->datastore($machine, [self::BACKUP_TASK => $this->finished(successful: true)]);

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupCompleted));
    }

    #[Test]
    public function a_failed_backup_tells_the_customer_once(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->runningBackupFor($customer, $machine);

        $this->datastore($machine, [self::BACKUP_TASK => $this->finished(successful: false, exitStatus: 'no space left on datastore')]);

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(BackupState::Failed, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::BackupFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::BackupCompleted));
    }

    // ---- 10-11. the restore's outcome comes from the restore --------------

    #[Test]
    public function restore_completed_is_raised_by_the_restore_task_and_not_the_backup(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $datastore = $this->datastore($machine, [
            self::BACKUP_TASK => $this->finished(successful: true),
            self::RESTORE_TASK => $this->running(),
        ]);

        // The backup task is finished and fine, and that must announce nothing
        // about a restore.
        app(ReconcileBackup::class)->execute($backup);
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreCompleted));

        $datastore->settle(self::RESTORE_TASK, $this->finished(successful: true));
        app(ReconcileBackup::class)->execute($backup->refresh());

        $this->assertSame(BackupState::Restored, $backup->refresh()->state);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted));
    }

    #[Test]
    public function restore_failed_is_raised_by_the_restore_task(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::BACKUP_TASK => $this->finished(successful: true),
            self::RESTORE_TASK => $this->finished(successful: false, exitStatus: 'target volume is read-only'),
        ]);

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreFailed));
        $this->assertSame(0, $this->notifications($customer, NotificationType::RestoreCompleted));
    }

    // ---- 12. a hundred sweeps are not a hundred messages ------------------

    #[Test]
    public function reconciling_repeatedly_does_not_tell_the_customer_repeatedly(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $this->datastore($machine, [
            self::BACKUP_TASK => $this->finished(successful: true),
            self::RESTORE_TASK => $this->finished(successful: true),
        ]);

        for ($i = 0; $i < 10; $i++) {
            app(ReconcileBackup::class)->execute($backup->refresh());
            app(ReconcileRunningBackups::class)->execute();
        }

        $this->assertSame(
            1,
            $this->notifications($customer, NotificationType::RestoreCompleted),
            'Reconciliation may run any number of times; the customer is told once.'
        );

        /*
         * And once more with the state guard taken out of the way, because
         * that loop alone proves the wrong thing.
         *
         * A settled row is no longer awaiting the provider, so the sweep above
         * returns before it announces anything — which means it would pass
         * with no dedup key at all. Removing the key entirely was measured and
         * the loop above did not notice. The mechanism that has to hold is the
         * unique index on the key, so the same terminal outcome is driven
         * through the transition a second time: two workers racing on one row,
         * or a redelivered settlement, land exactly here.
         */
        $backup->refresh()->transitionTo(BackupState::Restoring, ['restore_started_at' => now()]);

        app(ReconcileBackup::class)->execute($backup->refresh());

        $this->assertSame(
            1,
            $this->notifications($customer, NotificationType::RestoreCompleted),
            'The same restore settling twice is one event, and the customer hears about it once.'
        );
    }

    #[Test]
    public function a_second_restore_is_a_second_event_and_is_announced(): void
    {
        /*
         * The other half of the same rule, and the reason the key carries the
         * restore task rather than just the row. A customer who restores the
         * same backup again next month has asked for something new; a dedup
         * key that collapsed the two would leave them waiting for a message
         * that never comes.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->restoringBackupFor($customer, $machine);

        $datastore = $this->datastore($machine, [
            self::BACKUP_TASK => $this->finished(successful: true),
            self::RESTORE_TASK => $this->finished(successful: true),
        ]);

        app(ReconcileBackup::class)->execute($backup);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted));

        $second = 'UPID:pve-01:restore-0002';
        $datastore->settle($second, $this->finished(successful: true));

        $backup->refresh()->transitionTo(BackupState::Restoring, [
            'restore_task_id' => $second,
            'restore_started_at' => now(),
        ]);

        app(ReconcileBackup::class)->execute($backup->refresh());

        $this->assertSame(2, $this->notifications($customer, NotificationType::RestoreCompleted));
    }

    // ---- 13. one customer's restore is not another's ----------------------

    #[Test]
    public function one_customers_restore_never_reaches_another(): void
    {
        [$alice] = $this->accountWithOwner();
        [$bob] = $this->accountWithOwner();

        $machine = $this->machineFor($alice);
        $backup = $this->restoringBackupFor($alice, $machine);

        $this->datastore($machine, [
            self::BACKUP_TASK => $this->finished(successful: true),
            self::RESTORE_TASK => $this->finished(successful: true),
        ]);

        app(ReconcileBackup::class)->execute($backup);

        $this->assertSame(1, $this->notifications($alice, NotificationType::RestoreCompleted));
        $this->assertSame(
            0,
            Notification::query()->where('customer_id', $bob->getKey())->count(),
            "Another customer's machine is none of Bob's business."
        );
    }

    // ---- 14. one restore, however many times it is asked for --------------

    #[Test]
    public function a_duplicate_restore_request_starts_one_provider_operation(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine, verified: null);

        $datastore = $this->datastore($machine, [
            (string) $backup->provider_task_id => $this->finished(successful: true),
            self::RESTORE_TASK => $this->running(),
        ]);

        $url = '/api/v1/vps/'.$machine->id.'/backups/'.$backup->id.'/restore';
        $body = ['confirmation' => $machine->hostname];

        $this->actingAs($user)->postJson($url, $body)->assertStatus(202);

        // The customer pressing confirm again, and a client retrying the same
        // POST after a timeout, are the same request twice.
        $this->actingAs($user)->postJson($url, $body)->assertStatus(409);
        $this->actingAs($user)->postJson($url, $body)->assertStatus(409);

        // A reconciliation retry must not start one either: the poller reads,
        // it never writes a provider operation.
        for ($i = 0; $i < 3; $i++) {
            app(ReconcileRunningBackups::class)->execute();
        }

        $this->assertCount(
            1,
            $datastore->restoresStarted,
            'However many times a restore is asked for, the provider is told once.'
        );
    }

    // ---- fixtures ---------------------------------------------------------

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

    /**
     * `(provider, provider_task_id)` is unique, which is worth knowing here:
     * it is the same index RestoreServiceBackup's docblock cites as one reason
     * a restore's handle cannot be written over the backup's. A test that
     * needs two backups needs two task ids.
     */
    private function completedBackupFor(Customer $customer, VirtualMachine $machine, ?bool $verified): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster()->firstOrFail()->getKey(),
            'provider_task_id' => self::BACKUP_TASK.'-'.(++$this->backupsMade),
            'verified' => $verified,
        ]);
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

    /**
     * A backup whose restore the platform has started and is waiting on.
     *
     * The two identifiers are different, which is the whole point: this is the
     * row shape the defect lives in.
     */
    private function restoringBackupFor(Customer $customer, VirtualMachine $machine): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster()->firstOrFail()->getKey(),
            'provider_task_id' => self::BACKUP_TASK,
            'state' => BackupState::Restoring,
            'restore_task_id' => self::RESTORE_TASK,
            'restore_started_at' => now(),
        ]);
    }

    /**
     * A row the platform has asked the datastore to read back.
     *
     * Both columns carry the verification's handle, which is what
     * VerifyStoredArchives writes and why the poller's default branch is
     * correct for a verification and was wrong for a restore.
     */
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
