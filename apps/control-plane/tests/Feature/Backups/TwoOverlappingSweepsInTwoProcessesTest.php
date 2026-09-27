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
use Tests\Support\LeavesNothingCommitted;
use Tests\Support\RedisIndexForThisRun;
use Tests\TestCase;

/**
 * Two reconcile sweeps overlapping on one restoring row, each in its own
 * process (F-09).
 *
 * The in-process proof is
 * {@see ASweepSettlesOnlyTheAttemptItPolledTest::a_sweep_that_read_a_finished_restore_does_not_settle_the_next_one()},
 * where the stale reader is the sweep in this process and the others run
 * inside its batch load. Here nothing shares memory: sweep A is
 * `backups:reconcile` in a process of its own, held still just after loading
 * its batch (tests/Support/Processes/reconcile-backups-paused-after-its-batch.php);
 * sweep B is `backups:reconcile` in another, and settles the first restore; a
 * second restore of the same archive then starts; and only then is A let go,
 * with its copy of the row still naming the first restore's task, which has
 * finished. The processes share the database and the simulator's state file,
 * which is all the real scheduler's overlapping runs share.
 *
 * What must hold: A settles nothing, the second restore is still restoring on
 * its own task, and the customer has been told of one completed restore.
 */
final class TwoOverlappingSweepsInTwoProcessesTest extends TestCase
{
    use LeavesNothingCommitted;

    private string $statePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->statePath = sys_get_temp_dir().'/lynomia-overlapping-sweeps-'.getmypid().'-'.uniqid().'.state';

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
    public function a_sweep_let_go_after_another_settled_the_first_restore_does_not_settle_the_second(): void
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
            'provider_task_id' => 'UPID:fake:overlap-'.bin2hex(random_bytes(6)),
        ]);

        // The first restore, polled once: its task is still running.
        app(RestoreServiceBackup::class)->execute($archive->refresh(), $machine, $machine->hostname);
        app(ReconcileRunningBackups::class)->execute();
        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state);
        $firstTask = $row->restore_task_id;
        $this->assertNotNull($firstTask);

        // Sweep A loads that row, and waits.
        $lock = 7_300_000 + random_int(1, 99_999);
        DB::select('select pg_advisory_lock(?)', [$lock]);

        try {
            $sweepA = $this->sweep([PHP_BINARY, base_path('tests/Support/Processes/reconcile-backups-paused-after-its-batch.php')], ['SWEEP_PAUSE_LOCK' => (string) $lock]);
            $sweepA->start();

            $waited = microtime(true);
            while (! str_contains($sweepA->getErrorOutput(), 'PAUSED') && $sweepA->isRunning() && microtime(true) - $waited < 60) {
                usleep(50_000);
            }
            $this->assertStringContainsString('PAUSED', $sweepA->getErrorOutput(), 'Sweep A never loaded its batch: '.$sweepA->getErrorOutput().$sweepA->getOutput());

            // Sweep B, in another process, settles the first restore.
            $sweepB = $this->sweep([PHP_BINARY, 'artisan', 'backups:reconcile', '--no-interaction']);
            $sweepB->run();
            $this->assertTrue($sweepB->isSuccessful(), $sweepB->getErrorOutput().$sweepB->getOutput());
            $this->assertSame(BackupState::Restored, $archive->refresh()->state);

            // A second restore of the same archive starts.
            app(RestoreServiceBackup::class)->execute($archive->refresh(), $machine->fresh() ?? $machine, $machine->hostname);
            $row = $archive->refresh();
            $this->assertSame(BackupState::Restoring, $row->state);
            $secondTask = $row->restore_task_id;
            $this->assertNotNull($secondTask);
            $this->assertNotSame($firstTask, $secondTask);
        } finally {
            DB::select('select pg_advisory_unlock(?)', [$lock]);
        }

        // Sweep A is let go, holding the first restore's copy.
        $sweepA->wait();
        $this->assertStringContainsString('"considered":1', $sweepA->getOutput(), 'Sweep A did not work through the row it loaded: '.$sweepA->getErrorOutput());

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state, 'The stale sweep settled the second restore on the first restore\'s task.');
        $this->assertSame($secondTask, $row->restore_task_id);
        $this->assertNull($row->restored_at);
        $this->assertSame(1, Notification::query()
            ->where('customer_id', $customer->getKey())
            ->where('type', NotificationType::RestoreCompleted->value)
            ->count(), 'Only the first restore has completed.');
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $extra
     */
    private function sweep(array $command, array $extra = []): Process
    {
        return new Process(
            $command,
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
                ...$extra,
            ],
            null,
            120,
        );
    }
}
