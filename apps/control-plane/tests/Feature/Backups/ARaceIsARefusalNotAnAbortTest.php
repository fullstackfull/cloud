<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lynomia\Modules\Backups\Application\Actions\ReconcileBackup;
use Lynomia\Modules\Backups\Application\Actions\ReconcileBackupInventory;
use Lynomia\Modules\Backups\Application\Actions\RequestBackupDeletion;
use Lynomia\Modules\Backups\Application\Actions\RestoreBackupFiles;
use Lynomia\Modules\Backups\Application\Actions\RestoreServiceBackup;
use Lynomia\Modules\Backups\Application\Actions\VerifyStoredArchives;
use Lynomia\Modules\Backups\Domain\DTOs\BackupTaskState;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupFileRefusedException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileRestore;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationType;
use Lynomia\Modules\Notifications\Infrastructure\Models\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backups\Doubles\InterleavingDatastore;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * What every caller does when a backup row moved under it.
 *
 * `Backup::transitionTo` refuses a move from a state the row has left. That
 * refusal is only half the fix: each caller has to treat it as "somebody else
 * has this row" and carry on, and each has a window — a provider call, a
 * listing — in which the row can move. These drive the other request inside
 * that window, in-process, and check that the caller neither writes over it
 * nor falls over.
 */
final class ARaceIsARefusalNotAnAbortTest extends VpsApiTestCase
{
    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        config()->set('backups.verification_attempts', 3);
        $this->app->singleton(BackupProviderFactory::class);
    }

    #[Test]
    public function a_restore_landing_while_the_verify_call_is_out_leaves_the_sweep_skipping_quietly(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $archive = $this->archive($customer, $machine);
        $datastore = $this->interleaving($machine);

        $datastore->whileVerifying = function () use ($archive, $machine): void {
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine, $machine->hostname);
        };

        Log::spy();

        $sweep = app(VerifyStoredArchives::class)->execute();

        $this->assertSame(1, $datastore->verificationsStarted, 'The window this test is about: after the provider call.');
        $this->assertSame(1, $sweep->skipped, 'Somebody else\'s row now, and counted as such.');
        $this->assertSame(0, $sweep->failed);
        $this->assertSame(0, $sweep->settled);
        Log::shouldNotHaveReceived('error');

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state);
        $this->assertNotNull($row->restore_task_id);
        $this->assertNull($row->verification_task_id);
    }

    /**
     * The sweep reads the row again under a lock, and the lock ends with that
     * read. Its count of the attempt — `verification_requested_at` and
     * `verification_attempts`, written before the provider is asked — comes
     * after, and used to be a plain save: a restore started in between got a
     * verification attempt counted against it, and the datastore was asked to
     * verify an archive the sweep no longer had any business with.
     */
    #[Test]
    public function a_restore_landing_after_the_verify_sweep_read_the_row_is_not_counted_as_its_attempt(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $archive = $this->archive($customer, $machine);
        $datastore = $this->interleaving($machine);

        // The sweep reads the cluster after its locked read of the row.
        $fired = false;
        ComputeCluster::retrieved(function () use (&$fired, $archive, $machine): void {
            if ($fired) {
                return;
            }
            $fired = true;
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine->fresh(), $machine->hostname);
        });

        $sweep = app(VerifyStoredArchives::class)->execute();

        $this->assertTrue($fired);
        $this->assertSame(1, $sweep->skipped, 'Somebody else\'s row now, and counted as such.');
        $this->assertSame(0, $datastore->verificationsStarted, 'The datastore was not asked about a row that left the sweep.');

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state);
        $this->assertSame(0, $row->verification_attempts, 'No verification attempt was made on this row\'s behalf.');
        $this->assertNull($row->verification_requested_at);
    }

    /**
     * The same write in the branch for an archive whose cluster is gone,
     * which also records why: a deletion requested in between is not an
     * archive the sweep failed to read back.
     */
    #[Test]
    public function a_deletion_requested_after_the_verify_sweep_read_an_orphan_is_not_counted_as_its_attempt(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $orphan = $this->archive($customer, $machine, ['cluster_id' => null]);

        // The sweep's lookup of the (missing) cluster, after its locked read.
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired, $orphan): void {
            if ($fired || ! str_contains($query->sql, 'compute_clusters')) {
                return;
            }
            $fired = true;
            app(RequestBackupDeletion::class)->execute(Backup::query()->findOrFail($orphan->id), RequestBackupDeletion::BY_RETENTION);
        });

        $sweep = app(VerifyStoredArchives::class)->execute();

        $this->assertTrue($fired);
        $this->assertSame(1, $sweep->skipped);

        $row = $orphan->refresh();
        $this->assertSame(BackupState::DeleteRequested, $row->state);
        $this->assertSame(0, $row->verification_attempts);
        $this->assertNull($row->verification_requested_at);
        $this->assertNull($row->failure_reason, 'A sentence about a verification that was never attempted.');
    }

    #[Test]
    public function a_refused_verification_writes_its_reason_only_onto_a_row_still_waiting_for_one(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $archive = $this->archive($customer, $machine);
        $datastore = $this->interleaving($machine);
        $datastore->refuseVerification = true;

        $datastore->whileVerifying = function () use ($archive, $machine): void {
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine, $machine->hostname);
        };

        app(VerifyStoredArchives::class)->execute();

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state);
        $this->assertNull($row->failure_reason, 'A sentence about a refused verification says nothing true about a running restore.');

        // Control: with nothing racing, the refusal is recorded.
        $other = $this->archive($customer, $machine);
        app(VerifyStoredArchives::class)->execute();
        $this->assertStringContainsString('datastore busy', (string) $other->refresh()->failure_reason);
    }

    #[Test]
    public function the_inventory_sweep_skips_a_deletion_another_worker_confirmed_and_finishes_its_run(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $first = $this->archive($customer, $machine, ['state' => BackupState::Deleting]);
        $second = $this->archive($customer, $machine, ['state' => BackupState::Deleting]);

        // The retention sweep confirms the second while this sweep is
        // settling the first, from the batch it loaded before its listing.
        $fired = false;
        Backup::updated(function (Backup $row) use (&$fired, $first, $second): void {
            if ($fired || $row->id !== $first->id) {
                return;
            }
            $fired = true;
            Backup::query()->findOrFail($second->id)->transitionTo(BackupState::Deleted, ['provider_deleted_at' => now()]);
        });

        $result = app(ReconcileBackupInventory::class)->execute();

        $this->assertTrue($fired);
        $this->assertSame(1, $result['settled'], 'The first settled here; the second was somebody else\'s.');
        $this->assertSame(BackupState::Deleted, $first->refresh()->state);
        $this->assertSame(BackupState::Deleted, $second->refresh()->state);
    }

    #[Test]
    public function the_poller_leaves_a_row_another_worker_settled_while_it_asked(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $archive = $this->archive($customer, $machine, [
            'state' => BackupState::Verifying,
            'verification_task_id' => 'UPID:interleave:verify-x',
            'verification_started_at' => now(),
        ]);
        $datastore = $this->interleaving($machine);
        $datastore->settled['UPID:interleave:verify-x'] = new BackupTaskState(
            'UPID:interleave:verify-x', finished: true, successful: false, exitStatus: 'chunk missing',
        );

        // Another worker settles the same verification as readable while this
        // one is asking the datastore.
        $datastore->whileTaskIsAsked = function () use ($archive): void {
            Backup::query()->findOrFail($archive->id)->transitionTo(BackupState::Verified, ['verified' => true, 'verified_at' => now()]);
        };

        $returned = app(ReconcileBackup::class)->execute(Backup::query()->findOrFail($archive->id));

        $this->assertSame(BackupState::Verified, $returned->state, 'The row as it now stands.');
        $this->assertTrue($archive->refresh()->verified);
        $this->assertSame(0, Notification::query()
            ->where('customer_id', $customer->id)
            ->where('type', NotificationType::BackupVerificationFailed->value)
            ->count(), 'No verdict announced for a move that did not happen.');
    }

    #[Test]
    public function a_file_restore_checks_again_under_the_machine_lock(): void
    {
        /*
         * The file restore's first guard runs before it asks the provider
         * about the paths. A whole-machine restore landing in that window —
         * here, when the file restore reads the cluster — must be seen by the
         * second guard, the one under the machine's lock.
         */
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $source = $this->archive($customer, $machine);
        $whole = $this->archive($customer, $machine);
        app(BackupProviderFactory::class)->for($machine->cluster()->firstOrFail())->pollsBeforeSettling = 1_000;

        $fired = false;
        ComputeCluster::retrieved(function () use (&$fired, $whole, $machine): void {
            if ($fired) {
                return;
            }
            $fired = true;
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($whole->id), $machine->fresh(), $machine->hostname);
        });

        $refused = null;

        try {
            app(RestoreBackupFiles::class)->execute(
                Backup::query()->findOrFail($source->id),
                $machine->fresh(),
                [BackupPath::of('/etc/hostname')],
                $machine->hostname,
            );
        } catch (BackupFileRefusedException $e) {
            $refused = $e;
        }

        $this->assertTrue($fired);
        $this->assertSame(BackupState::Restoring, $whole->refresh()->state);
        $this->assertNotNull($refused);
        $this->assertSame('backup.file_restore_in_flight', $refused->errorCode());
        $this->assertSame(0, BackupFileRestore::query()->count(), 'No file restore over a whole-machine one.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function archive(Customer $customer, VirtualMachine $machine, array $overrides = []): Backup
    {
        return Backup::factory()->takenHoursAgo(72)->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
            'provider_task_id' => 'UPID:fake:own-'.(++$this->made),
            ...$overrides,
        ]);
    }

    private function interleaving(VirtualMachine $machine): InterleavingDatastore
    {
        $datastore = new InterleavingDatastore;
        app(BackupProviderFactory::class)->swap($machine->cluster()->firstOrFail(), $datastore);

        return $datastore;
    }
}
