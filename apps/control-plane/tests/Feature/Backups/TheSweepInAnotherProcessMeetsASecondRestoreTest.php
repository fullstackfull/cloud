<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Backups\Application\Actions\ReconcileRunningBackups;
use Lynomia\Modules\Backups\Application\Actions\RestoreServiceBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\ARestoreCallWithAWindow;
use Tests\Support\LeavesNothingCommitted;
use Tests\Support\RedisIndexForThisRun;
use Tests\TestCase;

/**
 * The scheduled sweep, in its own process, inside a second restore's provider
 * call (F-09).
 *
 * The in-process proof of the same rule is
 * {@see ASecondRestoreIsWatchedOnItsOwnTaskTest}. This one removes the last
 * comfortable assumption: `backups:reconcile` is started as `php artisan` in
 * another process, against committed rows and the simulator's shared state
 * file, at the moment the restore request has committed `restoring` and is
 * waiting on the provider. That process shares nothing with this one but the
 * database and the datastore — which is all the real scheduler shares.
 */
final class TheSweepInAnotherProcessMeetsASecondRestoreTest extends TestCase
{
    use LeavesNothingCommitted;

    private string $statePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath = sys_get_temp_dir().'/lynomia-backup-window-'.getmypid().'-'.uniqid().'.state';

        config()->set('billing.providers.backup', 'fake');
        config()->set('backups.fake.state_path', $this->statePath);
        config()->set('backups.max_poll_hours', 12);
        $this->app->singleton(BackupProviderFactory::class);
    }

    protected function tearDown(): void
    {
        $this->emptyEveryTable();

        if (is_file($this->statePath)) {
            @unlink($this->statePath);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_sweep_in_another_process_inside_the_provider_call_leaves_the_second_restore_restoring(): void
    {
        $this->assertSame(0, DB::transactionLevel(), 'These rows must be committed for another process to see them.');

        $customer = Customer::factory()->create(['currency' => 'KWD', 'country' => 'KW']);
        $service = Service::factory()->create(['customer_id' => $customer->id, 'kind' => 'vps', 'status' => ServiceStatus::Active]);
        $node = ComputeNode::factory()->create(['cluster_id' => ComputeCluster::factory()->create()->id]);
        $machine = VirtualMachine::factory()->onNode($node)->forService($service)->create();
        $machine = $machine->fresh() ?? $machine;

        $archive = Backup::factory()->takenHoursAgo(72)->create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
            'provider_task_id' => 'UPID:fake:committed-'.bin2hex(random_bytes(6)),
        ]);

        $window = ARestoreCallWithAWindow::installFor(app(BackupProviderFactory::class), $machine->cluster()->firstOrFail());

        // The first restore, run to the end.
        app(RestoreServiceBackup::class)->execute($archive->refresh(), $machine, $machine->hostname);
        app(ReconcileRunningBackups::class)->execute();
        app(ReconcileRunningBackups::class)->execute();
        $this->assertSame(BackupState::Restored, $archive->refresh()->state);
        $firstTask = $archive->restore_task_id;
        $this->assertNotNull($firstTask);

        // The second, with the scheduler's sweep inside its provider call.
        $sweep = null;
        $window->duringStartRestore = function () use (&$sweep, $window): void {
            $window->duringStartRestore = null;
            $sweep = $this->reconcileInAnotherProcess();
        };

        app(RestoreServiceBackup::class)->execute($archive->refresh(), $machine, $machine->hostname);

        $this->assertInstanceOf(Process::class, $sweep);
        $this->assertTrue($sweep->isSuccessful(), $sweep->getErrorOutput().$sweep->getOutput());
        $this->assertStringContainsString('"considered":', $sweep->getOutput(), 'The sweep ran and reported.');

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state, 'The sweep in the other process settled this restore on the previous restore\'s task.');
        $this->assertNotNull($row->restore_task_id);
        $this->assertNotSame($firstTask, $row->restore_task_id);
        $this->assertNull($row->restored_at);
        $this->assertSame(1, $this->notifications($customer, NotificationType::RestoreCompleted));

        // Its own task settles it, in another process too.
        $this->assertTrue($this->reconcileInAnotherProcess()->isSuccessful());
        $this->assertTrue($this->reconcileInAnotherProcess()->isSuccessful());

        $this->assertSame(BackupState::Restored, $archive->refresh()->state);
        $this->assertSame(2, $this->notifications($customer, NotificationType::RestoreCompleted));
    }

    private function reconcileInAnotherProcess(): Process
    {
        $process = new Process(
            [PHP_BINARY, 'artisan', 'backups:reconcile', '--no-interaction'],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
                'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
                'REDIS_DB' => (string) RedisIndexForThisRun::resolve(),
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
                'BACKUP_PROVIDER' => 'fake',
                'BACKUPS_FAKE_STATE_PATH' => $this->statePath,
            ],
            null,
            120,
        );

        $process->run();

        return $process;
    }

    private function notifications(Customer $customer, NotificationType $type): int
    {
        return Notification::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', $type->value)
            ->count();
    }
}
