<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\EnforceBackupRetention;
use Lynomia\Modules\Backups\Application\Actions\FileLevelSupport;
use Lynomia\Modules\Backups\Application\Actions\ReconcileFileRestores;
use Lynomia\Modules\Backups\Application\Actions\RestoreBackupFiles;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Enums\FileRestoreState;
use Lynomia\Modules\Backups\Domain\Exceptions\BackupFileRefusedException;
use Lynomia\Modules\Backups\Domain\ValueObjects\BackupPath;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Models\BackupFileRestore;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * A file restore clears the whole restore's bar, and holds its archive.
 *
 * Two gaps beside F-09 and F-10, found by the round-three re-audit:
 *
 *  - an archive the datastore read back and found unreadable (`verified =
 *    false`) is refused for a whole-machine restore, and was accepted (202)
 *    for a file restore — which writes the same unreadable data over the
 *    files it names. `FileLevelSupport` looked only at the state, so the
 *    portal's Files button was offered too;
 *  - the archive a file restore is reading from could be deleted under it:
 *    a customer's DELETE was 200 and the retention sweep marked it, because
 *    neither looked at `backup_file_restores`.
 */
final class AnArchiveUnderAFileRestoreTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        $this->app->singleton(BackupProviderFactory::class);
    }

    // ---- verified = false ---------------------------------------------------

    #[Test]
    public function an_archive_found_unreadable_is_refused_for_a_file_restore(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->archiveFor($customer, $machine, ['verified' => false, 'verified_at' => null]);

        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', [
                'paths' => ['/etc/hostname'],
                'confirmation' => $machine->hostname,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'backup.files_unavailable');

        $this->assertSame(0, BackupFileRestore::query()->count());

        // The row says the same thing the route does, so the Files button is
        // not offered for it.
        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/backups/'.$backup->id)
            ->assertOk()
            ->assertJsonPath('data.files.supported', false)
            ->assertJsonPath('data.is_restorable', false);

        $this->assertFalse(app(FileLevelSupport::class)->describe($backup)['supported']);
    }

    #[Test]
    public function an_archive_nobody_has_verified_is_still_offered(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->archiveFor($customer, $machine, ['verified' => null]);

        // Null is not a verdict: the same rule as the whole restore.
        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', [
                'paths' => ['/etc/hostname'],
                'confirmation' => $machine->hostname,
            ])
            ->assertStatus(202);
    }

    #[Test]
    public function a_verdict_written_after_the_first_look_is_read_again_under_the_lock(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->archiveFor($customer, $machine, ['verified' => null]);

        // The copy the request read says nothing; the table now says unreadable.
        $stale = Backup::query()->findOrFail($backup->id);
        Backup::query()->whereKey($backup->id)->update(['verified' => false]);

        // The provider was chosen on the stale copy; the guard under the lock
        // reads the row.
        try {
            app(RestoreBackupFiles::class)->execute($stale, $machine, [BackupPath::of('/etc/hostname')], $machine->hostname);
            $this->fail('An archive found unreadable must be refused.');
        } catch (BackupFileRefusedException $e) {
            $this->assertSame('backup.files_unavailable', $e->errorCode());
        }

        $this->assertSame(0, BackupFileRestore::query()->count());
    }

    // ---- the archive is held -----------------------------------------------

    #[Test]
    public function the_source_of_a_running_file_restore_cannot_be_deleted_by_the_customer(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->archiveFor($customer, $machine);

        $this->startAFileRestore($user, $machine, $backup);

        $this->actingAs($user)
            ->deleteJson('/api/v1/vps/'.$machine->id.'/backups/'.$backup->id, ['confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.restore_in_progress');

        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
    }

    #[Test]
    public function the_retention_sweep_does_not_mark_the_source_of_a_running_file_restore(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->archiveFor($customer, $machine, ['expires_at' => now()->addHour()]);

        $this->startAFileRestore($user, $machine, $backup);

        $this->travel(2)->hours();
        app(EnforceBackupRetention::class)->execute();

        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state, 'Retention must wait for the file restore.');
    }

    #[Test]
    public function an_archive_marked_before_the_guard_is_not_deleted_at_the_provider_while_a_file_restore_reads_it(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->archiveFor($customer, $machine);

        $this->startAFileRestore($user, $machine, $backup);

        // A mark written before RequestBackupDeletion looked at file restores.
        $backup->transitionTo(BackupState::DeleteRequested, [
            'deletion_requested_at' => now()->subDay(),
            'deletion_reason' => 'retention',
        ]);

        app(EnforceBackupRetention::class)->execute();

        $this->assertSame(BackupState::DeleteRequested, $backup->refresh()->state, 'The provider is not asked while the file restore reads the archive.');
        $this->assertSame(0, $backup->deletion_attempts);
    }

    #[Test]
    public function a_file_restore_in_review_still_holds_its_archive(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->archiveFor($customer, $machine);

        $restore = $this->startAFileRestore($user, $machine, $backup);
        $restore->forceFill(['state' => FileRestoreState::NeedsReview])->save();

        $this->actingAs($user)
            ->deleteJson('/api/v1/vps/'.$machine->id.'/backups/'.$backup->id, ['confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.restore_in_progress');
    }

    #[Test]
    public function once_the_file_restore_has_finished_the_archive_can_go(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->archiveFor($customer, $machine, ['expires_at' => now()->addHour()]);

        $restore = $this->startAFileRestore($user, $machine, $backup);

        // The simulator reports running once, then OK.
        app(ReconcileFileRestores::class)->execute();
        app(ReconcileFileRestores::class)->execute();
        $this->assertSame(FileRestoreState::Succeeded, $restore->refresh()->state);

        $this->travel(2)->hours();
        app(EnforceBackupRetention::class)->execute();

        $this->assertSame(BackupState::DeleteRequested, $backup->refresh()->state);
    }

    private function startAFileRestore(mixed $user, VirtualMachine $machine, Backup $backup): BackupFileRestore
    {
        $this->actingAs($user)
            ->postJson($this->files($machine, $backup).'/restore', [
                'paths' => ['/etc/hostname'],
                'confirmation' => $machine->hostname,
            ])
            ->assertStatus(202);

        $restore = BackupFileRestore::query()->sole();
        $this->assertSame(FileRestoreState::Running, $restore->state);

        return $restore;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function archiveFor(Customer $customer, VirtualMachine $machine, array $attributes = []): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
            ...$attributes,
        ]);
    }

    private function files(VirtualMachine $machine, Backup $backup): string
    {
        return '/api/v1/vps/'.$machine->id.'/backups/'.$backup->id.'/files';
    }
}
