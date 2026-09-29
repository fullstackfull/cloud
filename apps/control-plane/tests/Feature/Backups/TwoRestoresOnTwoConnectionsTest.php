<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Backups\Application\Actions\RestoreBackupFiles;
use Lynomia\Modules\Backups\Application\Actions\RestoreServiceBackup;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
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
use Tests\Support\LeavesNothingCommitted;
use Tests\TestCase;

/**
 * The machine's row is locked while a restore decides it may start.
 *
 * An in-process test cannot show a lock: one connection never waits on
 * itself. So the rows here are committed — no test transaction — and while a
 * restore is inside its guarded section, a second connection asks for the
 * same machine's row with `FOR UPDATE NOWAIT`. Postgres answers 55P03
 * (lock_not_available) exactly when another transaction holds the lock, which
 * is the property that makes two restore requests for one machine, arriving
 * in two processes at once, run their guards one after the other rather than
 * both passing.
 *
 * Every table is emptied afterwards (LeavesNothingCommitted, which asks the
 * test-database guard first), so a row the code under test writes by any
 * route is gone too.
 */
final class TwoRestoresOnTwoConnectionsTest extends TestCase
{
    use LeavesNothingCommitted;

    private const string SECOND = 'backup_race';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.'.self::SECOND, config('database.connections.pgsql'));
        config()->set('billing.providers.backup', 'fake');
        $this->app->singleton(BackupProviderFactory::class);
    }

    protected function tearDown(): void
    {
        DB::purge(self::SECOND);
        $this->emptyEveryTable();

        parent::tearDown();
    }

    #[Test]
    public function a_whole_machine_restore_holds_the_machine_while_it_checks_and_writes(): void
    {
        [$machine, $archive] = $this->committedMachineWithAnArchive();

        $seen = null;
        Backup::retrieved(function () use (&$seen, $machine): void {
            // The read of the backup row inside the guarded section.
            if ($seen !== null || DB::transactionLevel() === 0) {
                return;
            }
            $seen = $this->whatASecondConnectionGets($machine);
        });

        app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine, $machine->hostname);

        $this->assertSame('55P03', $seen, 'Another process must find the machine locked while a restore decides.');
        $this->assertSame(BackupState::Restoring, $archive->refresh()->state);
    }

    #[Test]
    public function a_file_restore_holds_the_machine_while_it_checks_and_writes(): void
    {
        [$machine, $archive] = $this->committedMachineWithAnArchive();

        $seen = null;
        BackupFileRestore::creating(function () use (&$seen, $machine): void {
            $seen ??= $this->whatASecondConnectionGets($machine);
        });

        app(RestoreBackupFiles::class)->execute(
            Backup::query()->findOrFail($archive->id),
            $machine,
            [BackupPath::of('/etc/hostname')],
            $machine->hostname,
        );

        $this->assertSame('55P03', $seen, 'Another process must find the machine locked while a file restore decides.');
    }

    /**
     * What another process gets when it asks for the machine's row: its lock
     * (null), or the SQLSTATE of the refusal.
     */
    private function whatASecondConnectionGets(VirtualMachine $machine): ?string
    {
        $other = DB::connection(self::SECOND);

        try {
            $other->beginTransaction();
            $other->select('select id from virtual_machines where id = ? for update nowait', [$machine->getKey()]);

            return null;
        } catch (QueryException $e) {
            return (string) $e->getCode();
        } finally {
            $other->rollBack();
        }
    }

    /**
     * @return array{0: VirtualMachine, 1: Backup}
     */
    private function committedMachineWithAnArchive(): array
    {
        $this->assertSame(0, DB::transactionLevel(), 'These rows must be committed for another connection to see them.');

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
