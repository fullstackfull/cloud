<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Backups\Application\Actions\ReconcileRunningBackups;
use Lynomia\Modules\Backups\Application\Actions\RestoreServiceBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Rbac\Domain\Enums\Role;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;
use Tests\Support\ARestoreCallWithAWindow;

/**
 * What an operator sees for a restore in review that has no task id, and where
 * a handle that arrived late is (re-audit after round six, band C,
 * unnumbered: `docs/runbooks/backup-failure.md` said step 1 gives "its task
 * id", and for these rows it gives none).
 *
 * The runbook now says: `restore_task_id` is null on the review list,
 * `failure_reason` says which of the two roads led there, and a handle that
 * arrives after the row went to review is not written onto it but logged as a
 * warning carrying the row's id and the handle. Each of those is held here.
 */
final class ARestoreInReviewWithoutAHandleIsFoundByItsLogLineTest extends VpsApiTestCase
{
    private const string LATE_HANDLE_LINE = 'A restore handle arrived after its attempt had ended; it was not written onto the row as it now stands.';

    private TestHandler $log;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        config()->set('backups.max_poll_hours', 12);
        $this->app->singleton(BackupProviderFactory::class);
        $this->seed(RolePermissionSeeder::class);

        $this->log = new TestHandler;
        Log::swap(new Logger(new \Monolog\Logger('testing', [$this->log])));
    }

    #[Test]
    public function a_restore_whose_start_timed_out_is_listed_with_no_task_id_and_the_providers_message(): void
    {
        $archive = $this->anArchive('vzdump-'.FakeBackupProvider::TIMEOUT_MARKER.'.vma.zst');
        $machine = $archive->virtualMachine()->firstOrFail();

        app(RestoreServiceBackup::class)->execute($archive, $machine, $machine->hostname);

        $row = $this->listed($archive);
        $this->assertSame('restoring', $row['interrupted_operation']);
        $this->assertNull($row['restore_task_id']);
        $this->assertNotNull($row['restore_started_at']);
        $this->assertIsString($row['failure_reason']);
        $this->assertNotSame('', $row['failure_reason']);
    }

    #[Test]
    public function a_handle_that_arrives_after_the_row_went_to_review_is_in_the_log_not_on_the_row(): void
    {
        $archive = $this->anArchive();
        $machine = $archive->virtualMachine()->firstOrFail();
        $window = ARestoreCallWithAWindow::installFor(app(BackupProviderFactory::class), $machine->cluster()->firstOrFail());

        $whileWaiting = null;
        $window->duringStartRestore = function () use ($window, $archive, &$whileWaiting): void {
            $window->duringStartRestore = null;

            // The call is still out when its window closes: the row goes to a person.
            $this->travel(13)->hours();
            app(ReconcileRunningBackups::class)->execute();
            $whileWaiting = $this->listed($archive);
        };

        app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine, $machine->hostname);

        $this->assertIsArray($whileWaiting);
        $this->assertNull($whileWaiting['restore_task_id']);
        $this->assertStringContainsString('the platform has stopped tracking it', (string) $whileWaiting['failure_reason']);

        // The handle arrived; the row in review does not carry it ...
        $this->assertSame(BackupState::NeedsReview, $archive->refresh()->state);
        $this->assertNull($this->listed($archive)['restore_task_id']);

        // ... the log does, against the row's id.
        $lines = array_values(array_filter(
            $this->log->getRecords(),
            static fn (LogRecord $record): bool => $record->message === self::LATE_HANDLE_LINE,
        ));
        $this->assertCount(1, $lines);
        $this->assertSame('WARNING', $lines[0]->level->getName());
        $this->assertSame($archive->id, $lines[0]->context['backup_id']);
        $this->assertIsString($lines[0]->context['restore_task_id']);
        $this->assertNotSame('', $lines[0]->context['restore_task_id']);
    }

    private function anArchive(?string $archiveId = null): Backup
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        return Backup::factory()->takenHoursAgo(72)->create(array_filter([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
            'provider_task_id' => 'UPID:fake:taken-once',
            'archive_id' => $archiveId,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function listed(Backup $archive): array
    {
        $operator = User::factory()->create();
        $operator->syncRoles([Role::SuperAdmin->value]);

        $rows = $this->actingAs($operator)->getJson('/api/admin/backups/needs-review?per_page=100')->assertOk()->json('data');

        foreach ($rows as $row) {
            if ($row['id'] === $archive->id) {
                return $row;
            }
        }

        $this->fail('The row is not on the review list.');
    }
}
