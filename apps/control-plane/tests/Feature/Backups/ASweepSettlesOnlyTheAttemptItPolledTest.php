<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\DeleteBackupAtProvider;
use Lynomia\Modules\Backups\Application\Actions\ReconcileBackup;
use Lynomia\Modules\Backups\Application\Actions\ReconcileRunningBackups;
use Lynomia\Modules\Backups\Application\Actions\RequestBackupDeletion;
use Lynomia\Modules\Backups\Application\Actions\RestoreServiceBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\IllegalBackupTransitionException;
use Lynomia\Modules\Backups\Domain\Exceptions\RestoreRefusedException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Vps\VpsApiTestCase;
use Tests\Support\ARestoreCallWithAWindow;

/**
 * A sweep settles the attempt it polled, and no later one (F-09).
 *
 * `ReconcileRunningBackups` loads up to two hundred rows and then asks the
 * provider about them one at a time, and two sweeps can overlap (the
 * scheduler's overlap lock expires, or somebody runs the command by hand).
 * The compare-and-set in `Backup::transitionTo()` compared the state alone,
 * and one archive can be `restoring` twice: sweep A read the row while its
 * first restore was running, sweep B settled that restore, a customer started
 * a second one, and A — still holding the first attempt's handle — polled the
 * finished first task and wrote `Restored` (or, if it had failed,
 * `Succeeded`) over the second restore, which was never polled. The machine
 * was released to a third restore while the second was writing its disks,
 * and the customer was told a restore had ended that had not.
 *
 * The stale reader here is the production sweep itself: the other sweep and
 * the second restore run while it is hydrating the batch it will work
 * through, which is the moment a second process would have to land in.
 */
final class ASweepSettlesOnlyTheAttemptItPolledTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        config()->set('backups.max_poll_hours', 12);
        $this->app->singleton(BackupProviderFactory::class);
    }

    #[Test]
    public function a_sweep_that_read_a_finished_restore_does_not_settle_the_next_one(): void
    {
        // One clock for the whole run: both restores start in the same second,
        // so only the task tells the two attempts apart.
        $this->freezeTime();
        [$customer, $machine, $archive, $firstTask] = $this->aRestoreRunning();

        $this->whileTheSweepLoadsItsBatch($archive, $machine, minutesBetween: 0);

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state, 'The second restore was never polled; nothing can say it ended.');
        $this->assertNotNull($row->restore_task_id);
        $this->assertNotSame($firstTask, $row->restore_task_id);
        $this->assertNull($row->restored_at);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted), 'Only the first restore has completed.');
        $this->assertSame('backup.restore_in_flight', $this->aThirdRestore($archive, $machine), 'The machine is still held by the second restore.');

        // The second restore settles on its own task, in its own words.
        app(ReconcileRunningBackups::class)->execute();
        app(ReconcileRunningBackups::class)->execute();

        $this->assertSame(BackupState::Restored, $archive->refresh()->state);
        $this->assertSame(2, $this->notifications($customer, NotificationType::RestoreCompleted));
    }

    #[Test]
    public function a_sweep_that_read_a_failed_restore_does_not_fail_the_next_one(): void
    {
        [$customer, $machine, $archive, $firstTask] = $this->aRestoreRunning(failing: true);

        $this->whileTheSweepLoadsItsBatch($archive, $machine, minutesBetween: 5);

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state, 'The first restore\'s failure is not the second\'s.');
        $this->assertNotSame($firstTask, $row->restore_task_id);
        $this->assertNull($row->failure_reason);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreFailed));
        $this->assertSame('backup.restore_in_flight', $this->aThirdRestore($archive, $machine));

        app(ReconcileRunningBackups::class)->execute();
        app(ReconcileRunningBackups::class)->execute();

        $this->assertSame(BackupState::Succeeded, $archive->refresh()->state);
        $this->assertSame(2, $this->notifications($customer, NotificationType::RestoreFailed), 'The second failure is its own message, not swallowed as a duplicate.');
    }

    #[Test]
    public function a_sweep_that_read_an_overdue_handleless_restore_does_not_hand_the_next_one_to_a_person(): void
    {
        [$user, $machine, $archive] = $this->anArchive();
        $window = ARestoreCallWithAWindow::installFor(app(BackupProviderFactory::class), $machine->cluster()->firstOrFail());

        // The first restore's process dies inside the provider call: no handle.
        $window->duringStartRestore = static function (): void {
            throw new RuntimeException('the request process died inside the provider call');
        };

        try {
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine, $machine->hostname);
            $this->fail('The simulated crash must escape.');
        } catch (RuntimeException) {
        }

        $this->travel(13)->hours();

        // A sweep's copy of the first attempt: `restoring`, no handle, overdue.
        $stale = Backup::query()->findOrFail($archive->id);
        $this->assertNull($stale->restore_task_id);

        // Another sweep hands it to a person, who says it did not complete.
        app(ReconcileRunningBackups::class)->execute();
        $this->assertSame(BackupState::NeedsReview, $archive->refresh()->state);
        Backup::query()->findOrFail($archive->id)->settleReview(false);

        // A second restore; the stale sweep reaches the row inside its call,
        // before its handle is written. Same state, same (absent) handle —
        // only the start tells the two attempts apart.
        $window->duringStartRestore = static function () use ($stale): void {
            app(ReconcileBackup::class)->execute($stale);
        };
        app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine, $machine->hostname, (string) $user->getKey());

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state, 'A restore that started a moment ago is not overdue.');
        $this->assertNotNull($row->restore_task_id);
        $this->assertNull($row->failure_reason);
    }

    #[Test]
    public function a_verdict_read_for_an_earlier_review_does_not_settle_a_later_one(): void
    {
        [, , $archive] = $this->anArchive();

        $archive->transitionTo(BackupState::Restoring, ['restore_task_id' => 'UPID:fake-restore:first', 'restore_started_at' => now()->subHours(20)]);
        $archive->transitionTo(BackupState::NeedsReview, ['failure_reason' => 'first attempt lost']);

        // A copy of the first review.
        $stale = Backup::query()->findOrFail($archive->id);

        $fresh = Backup::query()->findOrFail($archive->id);
        $fresh->settleReview(false);
        $fresh->transitionTo(BackupState::Restoring, ['restore_task_id' => 'UPID:fake-restore:second', 'restore_started_at' => now()]);
        $fresh->transitionTo(BackupState::NeedsReview, ['failure_reason' => 'second attempt lost']);

        $this->assertRaced(fn () => $stale->settleReview(true));

        $row = $archive->refresh();
        $this->assertSame(BackupState::NeedsReview, $row->state, 'A verdict on the first restore is not one on the second.');
        $this->assertSame('UPID:fake-restore:second', $row->restore_task_id);
        $this->assertNull($row->restored_at);
    }

    #[Test]
    public function a_copy_read_during_an_earlier_verification_does_not_settle_a_later_one(): void
    {
        [, , $archive] = $this->anArchive();

        $archive->transitionTo(BackupState::Verifying, ['verification_task_id' => 'UPID:fake-verify:first', 'verification_started_at' => now()->subHours(2)]);
        $stale = Backup::query()->findOrFail($archive->id);

        $fresh = Backup::query()->findOrFail($archive->id);
        $fresh->transitionTo(BackupState::Verified, ['verified' => true, 'verified_at' => now()]);
        $fresh->transitionTo(BackupState::Verifying, ['verification_task_id' => 'UPID:fake-verify:second', 'verification_started_at' => now()]);

        $this->assertRaced(fn () => $stale->transitionTo(BackupState::Failed, ['verified' => false]));

        $row = $archive->refresh();
        $this->assertSame(BackupState::Verifying, $row->state);
        $this->assertSame('UPID:fake-verify:second', $row->verification_task_id);
        $this->assertTrue($row->verified, 'Nothing was written from the stale copy.');
    }

    #[Test]
    public function a_copy_read_during_a_called_off_deletion_does_not_act_on_a_later_one(): void
    {
        [$user, , $archive] = $this->anArchive();

        app(RequestBackupDeletion::class)->execute($archive, RequestBackupDeletion::BY_RETENTION, null, now()->subHours(3));

        // The retention sweep's copy of the first request.
        $stale = Backup::query()->findOrFail($archive->id);

        app(RequestBackupDeletion::class)->cancel(Backup::query()->findOrFail($archive->id));
        app(RequestBackupDeletion::class)->execute(Backup::query()->findOrFail($archive->id), RequestBackupDeletion::BY_CUSTOMER, $user);

        app(DeleteBackupAtProvider::class)->execute($stale);

        $row = $archive->refresh();
        $this->assertSame(BackupState::DeleteRequested, $row->state, 'The request this copy read was called off; the one standing now is not its to act on.');
        $this->assertSame(RequestBackupDeletion::BY_CUSTOMER, $row->deletion_reason);
        $this->assertSame(0, $row->deletion_attempts, 'The provider was not asked on the strength of the stale copy.');
    }

    /**
     * Sweep A hydrates its batch; before it polls anything, sweep B settles
     * the first restore and the customer starts a second one.
     */
    private function whileTheSweepLoadsItsBatch(Backup $archive, VirtualMachine $machine, int $minutesBetween): void
    {
        $fired = false;
        Backup::retrieved(function (Backup $loaded) use (&$fired, $archive, $machine, $minutesBetween): void {
            if ($fired || $loaded->id !== $archive->id) {
                return;
            }
            $fired = true;

            app(ReconcileRunningBackups::class)->execute();
            $this->assertNotSame(BackupState::Restoring, $archive->refresh()->state, 'Sweep B settled the first restore.');

            $this->travel($minutesBetween)->minutes();
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine, $machine->hostname);
        });

        app(ReconcileRunningBackups::class)->execute();

        $this->assertTrue($fired, 'Sweep A read the row while the first restore was running.');
    }

    /**
     * @return array{0: Customer, 1: VirtualMachine, 2: Backup, 3: string}
     */
    private function aRestoreRunning(bool $failing = false): array
    {
        [$user, $machine, $archive] = $this->anArchive($failing);

        $this->actingAs($user)
            ->postJson('/api/v1/vps/'.$machine->id.'/backups/'.$archive->id.'/restore', ['confirmation' => $machine->hostname])
            ->assertStatus(202);

        // The simulator reports running once, then settles.
        app(ReconcileRunningBackups::class)->execute();

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state);
        $this->assertNotNull($row->restore_task_id);

        /** @var Customer $customer */
        $customer = Customer::query()->findOrFail($archive->customer_id);

        return [$customer, $machine, $archive, (string) $row->restore_task_id];
    }

    /**
     * @return array{0: User, 1: VirtualMachine, 2: Backup}
     */
    private function anArchive(bool $failing = false): array
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $archive = Backup::factory()->takenHoursAgo(72)->create(array_filter([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
            'provider_task_id' => 'UPID:fake:taken-once',
            // The simulator fails every restore of an archive named so.
            'archive_id' => $failing ? 'vzdump-qemu-100-'.FakeBackupProvider::FAILING_MARKER.'.vma.zst' : null,
        ], static fn (mixed $value): bool => $value !== null));

        return [$user, $machine, $archive];
    }

    private function aThirdRestore(Backup $archive, VirtualMachine $machine): string
    {
        try {
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine, $machine->hostname);

            return 'accepted';
        } catch (RestoreRefusedException $e) {
            return $e->errorCode();
        }
    }

    private function assertRaced(callable $move): void
    {
        try {
            $move();
            $this->fail('A move from an attempt the row has left must be refused.');
        } catch (IllegalBackupTransitionException $e) {
            $this->assertTrue($e->wasRaced(), $e->getMessage());
        }
    }

    private function notifications(Customer $customer, NotificationType $type): int
    {
        return Notification::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', $type->value)
            ->count();
    }
}
