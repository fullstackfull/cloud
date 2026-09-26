<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\ReconcileRunningBackups;
use Lynomia\Modules\Backups\Application\Actions\RestoreServiceBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\RestoreRefusedException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupNotificationKey;
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
 * A second restore of one archive is watched on its own task (F-09).
 *
 * The row moved to `restoring` with the previous restore's `restore_task_id`
 * still on it, and the new handle was written only once the provider call
 * returned. A sweep landing in between — or a process dying there — polled the
 * previous restore's task, which had finished long ago, and wrote `Restored`:
 * the machine was released to another restore while this one was writing its
 * disks, and the customer was told it was back. Its message was keyed on the
 * stale task, so the true message later was swallowed as a duplicate.
 *
 * Every archive here has been restored once already, through the real request
 * and the real sweep, on the shipped simulator.
 */
final class ASecondRestoreIsWatchedOnItsOwnTaskTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        config()->set('backups.max_poll_hours', 12);
        $this->app->singleton(BackupProviderFactory::class);
    }

    #[Test]
    public function a_second_restore_polled_before_its_handle_arrives_stays_restoring(): void
    {
        [$customer, $user, $machine, $archive, $window] = $this->anArchiveRestoredOnce();
        $firstTask = $archive->refresh()->restore_task_id;

        $seen = null;
        $third = null;
        $window->duringStartRestore = function () use (&$seen, &$third, $archive, $machine, $window): void {
            // Once: a third restore that got through would call back in here.
            $window->duringStartRestore = null;

            // The sweep, between the transition and the handle.
            app(ReconcileRunningBackups::class)->execute();
            $seen = $archive->refresh()->replicate();

            // And another restore of the same disks, while this one starts.
            try {
                app(RestoreServiceBackup::class)->execute($archive->refresh(), $machine, $machine->hostname);
                $third = 'accepted';
            } catch (RestoreRefusedException $e) {
                $third = $e->errorCode();
            }
        };

        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $archive), ['confirmation' => $machine->hostname])
            ->assertStatus(202);

        $this->assertNotNull($seen);
        $this->assertSame(BackupState::Restoring, $seen->state, 'A sweep inside the provider call must not settle this restore on the previous one\'s task.');
        $this->assertNull($seen->restore_task_id, 'The previous restore\'s handle is not this restore\'s.');
        $this->assertNull($seen->restored_at, 'This attempt has not restored anything yet.');
        $this->assertSame('backup.restore_in_flight', $third, 'The machine is held while this restore starts.');

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state);
        $this->assertNotNull($row->restore_task_id);
        $this->assertNotSame($firstTask, $row->restore_task_id);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted), 'Nobody was told a restore that is still writing had finished.');

        // And the restore settles on its own task, with a message of its own.
        app(ReconcileRunningBackups::class)->execute();
        app(ReconcileRunningBackups::class)->execute();

        $this->assertSame(BackupState::Restored, $archive->refresh()->state);
        $this->assertSame(2, $this->notifications($customer, NotificationType::RestoreCompleted), 'The second restore is a second event.');
    }

    #[Test]
    public function a_second_restore_after_a_failed_one_is_not_settled_on_the_failed_task(): void
    {
        [$customer, $user, $machine, $archive, $window] = $this->anArchiveRestoredOnce(failing: true);
        $firstTask = $archive->refresh()->restore_task_id;

        $seen = null;
        $window->duringStartRestore = function () use (&$seen, $archive): void {
            app(ReconcileRunningBackups::class)->execute();
            $seen = $archive->refresh()->replicate();
        };

        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $archive), ['confirmation' => $machine->hostname])
            ->assertStatus(202);

        $this->assertNotNull($seen);
        $this->assertSame(BackupState::Restoring, $seen->state, 'The previous restore\'s failure is not this restore\'s.');
        $this->assertNull($seen->restore_task_id);
        $this->assertNull($seen->failure_reason, 'A running restore carries no previous reason.');

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state);
        $this->assertNotSame($firstTask, $row->restore_task_id);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreFailed));

        app(ReconcileRunningBackups::class)->execute();
        app(ReconcileRunningBackups::class)->execute();

        // This archive fails on the simulator every time; the second failure
        // is the second restore's, and a second message.
        $this->assertSame(BackupState::Succeeded, $archive->refresh()->state);
        $this->assertSame(2, $this->notifications($customer, NotificationType::RestoreFailed));
    }

    #[Test]
    public function a_crash_between_the_transition_and_the_handle_leaves_the_row_to_its_own_clock(): void
    {
        [$customer, $user, $machine, $archive, $window] = $this->anArchiveRestoredOnce();

        $window->duringStartRestore = static function (): void {
            throw new RuntimeException('the request process died inside the provider call');
        };

        try {
            app(RestoreServiceBackup::class)->execute($archive->refresh(), $machine, $machine->hostname, (string) $user->getKey());
            $this->fail('The simulated crash must escape.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('died', $e->getMessage());
        }

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state);
        $this->assertNull($row->restore_task_id, 'No handle arrived; none may be left from before.');

        app(ReconcileRunningBackups::class)->execute();
        $this->assertSame(BackupState::Restoring, $archive->refresh()->state, 'A handle-less restore is left for its handle.');

        $this->travel(11)->hours();
        app(ReconcileRunningBackups::class)->execute();
        $this->assertSame(BackupState::Restoring, $archive->refresh()->state, 'Eleven hours into a twelve-hour window.');

        $this->travel(2)->hours();
        app(ReconcileRunningBackups::class)->execute();

        $row = $archive->refresh();
        $this->assertSame(BackupState::NeedsReview, $row->state);
        $this->assertSame(BackupState::Restoring, $row->quarantined_from);
        $this->assertStringContainsString('13 hours', (string) $row->failure_reason);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted), 'Only the first restore ever completed.');
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreNeedsReview));
    }

    #[Test]
    public function a_restore_message_is_keyed_to_its_attempt_and_not_only_to_its_task(): void
    {
        $first = BackupNotificationKey::restore('b', 'UPID:1', 'restored', '2026-01-01T00:00:00+00:00');
        $second = BackupNotificationKey::restore('b', 'UPID:1', 'restored', '2026-02-01T00:00:00+00:00');

        $this->assertNotSame($first, $second, 'Two attempts are two events even if a provider reuses a task handle.');
        $this->assertSame($first, BackupNotificationKey::restore('b', 'UPID:1', 'restored', '2026-01-01T00:00:00+00:00'));
        $this->assertNotSame(
            BackupNotificationKey::restore('b', null, 'needs_review', '2026-01-01T00:00:00+00:00'),
            BackupNotificationKey::restore('b', null, 'needs_review', '2026-02-01T00:00:00+00:00'),
        );
    }

    /**
     * @return array{0: Customer, 1: User, 2: VirtualMachine, 3: Backup, 4: ARestoreCallWithAWindow}
     */
    private function anArchiveRestoredOnce(bool $failing = false): array
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

        $window = ARestoreCallWithAWindow::installFor(
            app(BackupProviderFactory::class),
            $machine->cluster()->firstOrFail(),
        );

        $this->actingAs($user)
            ->postJson($this->restoreUrl($machine, $archive), ['confirmation' => $machine->hostname])
            ->assertStatus(202);

        // The simulator reports running once, then OK.
        app(ReconcileRunningBackups::class)->execute();
        app(ReconcileRunningBackups::class)->execute();

        $this->assertSame($failing ? BackupState::Succeeded : BackupState::Restored, $archive->refresh()->state);
        $this->assertNotNull($archive->restore_task_id);
        $this->assertSame(1, $this->notifications(
            $customer,
            $failing ? NotificationType::RestoreFailed : NotificationType::RestoreCompleted,
        ));

        $this->travel(1)->hours();

        return [$customer, $user, $machine, $archive, $window];
    }

    private function restoreUrl(VirtualMachine $machine, Backup $backup): string
    {
        return '/api/v1/vps/'.$machine->id.'/backups/'.$backup->id.'/restore';
    }

    private function notifications(Customer $customer, NotificationType $type): int
    {
        return Notification::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', $type->value)
            ->count();
    }
}
