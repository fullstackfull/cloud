<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Backups\Application\Actions\RequestBackupDeletion;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileRestore;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\LeavesNothingCommitted;
use Tests\Support\RedisIndexForThisRun;
use Tests\TestCase;

/**
 * A file restore started while a deletion holds the archive waits for it, and
 * then sees the deletion.
 *
 * RequestBackupDeletion locks the archive's row, checks that no file restore
 * is reading it, and marks it; RestoreBackupFiles locks the same row before it
 * checks the archive can be opened and writes its own row. That shared lock
 * is the whole of what keeps the two apart when the deletion goes first: a
 * file restore that read the row without it saw the committed `succeeded`
 * beside the deletion's uncommitted mark, passed, and wrote a running file
 * restore of an archive that was marked for deletion a moment later — the
 * one pair of rows the deletion's own check exists to rule out.
 *
 * In-process this cannot be shown (one connection never waits on itself), so
 * the rows are committed, the deletion runs here inside a transaction that is
 * held open once the action has checked and marked, and the file restore runs
 * in another process ({@see file_restore_racer.php}) through the shipped
 * action. The deletion commits only once that process is either blocked on a
 * lock or finished — a condition read from `pg_stat_activity`, not a sleep —
 * so the outcome does not depend on timing: with the lock, the file restore
 * waited and was refused; without it, it had already been accepted.
 *
 * Every table is emptied afterwards (LeavesNothingCommitted).
 */
final class AFileRestoreWaitsForADeletionThatHoldsTheArchiveTest extends TestCase
{
    use LeavesNothingCommitted;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        $this->app->singleton(BackupProviderFactory::class);
    }

    #[Test]
    public function a_file_restore_started_while_a_deletion_holds_the_archive_is_refused(): void
    {
        [$machine, $archive] = $this->committedMachineWithAnArchive();

        DB::beginTransaction();

        try {
            // The deletion goes first: it has locked the archive, found no
            // file restore reading it, and marked it — and has not committed.
            app(RequestBackupDeletion::class)->execute(Backup::query()->findOrFail($archive->id), RequestBackupDeletion::BY_RETENTION);

            $request = $this->fileRestoreInAnotherProcess($archive, $machine);
            $blocked = $this->untilBlockedOrFinished($request);
        } finally {
            DB::commit();
        }

        $request->wait();
        $line = trim($request->getOutput());
        $this->assertNotSame('', $line, 'The file restore produced no verdict: '.$request->getErrorOutput());

        /** @var array{accepted: bool, code: ?string, state: ?string} $outcome */
        $outcome = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $row = $archive->refresh();
        $this->assertSame(BackupState::DeleteRequested, $row->state, 'The deletion stands.');
        $this->assertSame(
            0,
            BackupFileRestore::query()->where('backup_id', $archive->id)->count(),
            'A file restore was written for an archive a deletion had already marked: '.$line,
        );
        $this->assertFalse($outcome['accepted'], $line);
        $this->assertSame('backup.files_unavailable', $outcome['code'], $line);
        $this->assertTrue($blocked, 'The file restore waited on the deletion\'s lock rather than finishing first.');
    }

    /**
     * Whether the other process was seen waiting on a lock (true) or had
     * already finished (false).
     */
    private function untilBlockedOrFinished(Process $request): bool
    {
        $deadline = microtime(true) + 45.0;

        while ($request->isRunning()) {
            // This connection is inside the deletion's transaction, and the
            // statistics views are read once per transaction unless the
            // snapshot is dropped: without this it would never see the other
            // process arrive.
            DB::select('SELECT pg_stat_clear_snapshot()');

            $waiting = (int) DB::scalar(
                "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND pid <> pg_backend_pid() AND wait_event_type = 'Lock'"
            );

            if ($waiting > 0) {
                return true;
            }

            if (microtime(true) > $deadline) {
                $request->stop(0);
                $this->fail('The file restore neither waited nor finished: '.$request->getErrorOutput());
            }

            usleep(20_000);
        }

        return false;
    }

    private function fileRestoreInAnotherProcess(Backup $archive, VirtualMachine $machine): Process
    {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/file_restore_racer.php', (string) $archive->id, (string) $machine->id],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
                'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
                'REDIS_DB' => (string) RedisIndexForThisRun::resolve(),
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
                'BACKUP_PROVIDER' => 'fake',
            ],
            null,
            60.0,
        );

        $process->start();

        return $process;
    }

    /**
     * @return array{0: VirtualMachine, 1: Backup}
     */
    private function committedMachineWithAnArchive(): array
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

        return [$machine, $archive];
    }
}
