<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\DeleteBackupAtProvider;
use Lynomia\Modules\Backups\Application\Actions\RequestBackupDeletion;
use Lynomia\Modules\Backups\Application\Actions\RestoreServiceBackup;
use Lynomia\Modules\Backups\Application\Actions\VerifyStoredArchives;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Domain\Exceptions\IllegalBackupTransitionException;
use Lynomia\Modules\Backups\Domain\Exceptions\RestoreRefusedException;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * A backup row is never moved on the strength of a copy read before somebody
 * else moved it.
 *
 * Every sweep here loads a batch and then makes one provider call per row;
 * every request reads a row, checks it, asks a question of something else and
 * then writes. `Backup::transitionTo` used to check the state it held in
 * memory and save over whatever the table said. So:
 *
 *  - the verification sweep wrote `verifying` over a restore that a customer
 *    had started on a row the sweep had already loaded — the restore was
 *    never polled again, and a second restore of the machine was accepted;
 *  - the same sweep wrote `verifying` over a customer's `delete_requested`;
 *  - a restore wrote `restoring` over a verification the sweep had just
 *    started on the same archive;
 *  - two restore requests for two archives of one machine both passed the
 *    in-flight guard before either wrote `restoring`;
 *  - the retention sweep's stale `delete_requested` copy moved to `deleting`
 *    — and asked the provider to delete — an archive that had since been
 *    kept and was being restored.
 *
 * Each interleaving is produced in-process, with a model event standing in
 * for the other request, at the exact point the verifier found it.
 */
final class NoOneMovesABackupOnAStaleReadTest extends VpsApiTestCase
{
    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        config()->set('backups.max_poll_hours', 12);
        config()->set('backups.verification_attempts', 3);
        $this->app->singleton(BackupProviderFactory::class);
    }

    #[Test]
    public function the_verification_sweep_does_not_overwrite_a_restore_started_mid_sweep(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $first = $this->archive($customer, $machine, 96);
        $restored = $this->archive($customer, $machine, 72);
        $other = $this->archive($customer, $machine, 48, ['verification_attempts' => 3]);
        $this->fake($machine)->pollsBeforeSettling = 1_000;

        // A customer's restore lands while the sweep works on the first row of
        // the batch it has already loaded.
        $this->onceWhenUpdated($first, function () use ($restored, $machine): void {
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($restored->id), $machine, $machine->hostname);
        });

        app(VerifyStoredArchives::class)->execute();

        $row = $restored->refresh();
        $this->assertSame(BackupState::Restoring, $row->state, 'The restore is what this row is doing.');
        $this->assertNotNull($row->restore_task_id);
        $this->assertNull($row->verification_task_id, 'No verification was started over a restore.');
        $this->assertSame(0, $row->verification_attempts, 'The datastore was not even asked: the row was read again first.');

        $this->actingAs($user)
            ->postJson('/api/v1/vps/'.$machine->id.'/backups/'.$other->id.'/restore', ['confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.restore_in_flight');
    }

    #[Test]
    public function the_verification_sweep_does_not_overwrite_a_deletion_requested_mid_sweep(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $first = $this->archive($customer, $machine, 96);
        $doomed = $this->archive($customer, $machine, 72);
        $this->fake($machine)->pollsBeforeSettling = 1_000;

        $this->onceWhenUpdated($first, function () use ($doomed, $user): void {
            app(RequestBackupDeletion::class)->execute(Backup::query()->findOrFail($doomed->id), RequestBackupDeletion::BY_CUSTOMER, $user);
        });

        app(VerifyStoredArchives::class)->execute();

        $row = $doomed->refresh();
        $this->assertSame(BackupState::DeleteRequested, $row->state, 'The customer\'s decision stands.');
        $this->assertNull($row->verification_task_id);
        $this->assertSame(0, $row->verification_attempts);
    }

    #[Test]
    public function two_restores_of_one_machine_cannot_both_start(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $a = $this->archive($customer, $machine, 72);
        $b = $this->archive($customer, $machine, 72);
        $this->fake($machine)->pollsBeforeSettling = 1_000;

        // The second request arrives after the first has read its cluster and
        // before it has written `restoring`.
        $fired = false;
        ComputeCluster::retrieved(function () use (&$fired, $b, $machine): void {
            if ($fired) {
                return;
            }
            $fired = true;
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($b->id), $machine->fresh(), $machine->hostname);
        });

        $refused = null;

        try {
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($a->id), $machine->fresh(), $machine->hostname);
        } catch (RestoreRefusedException $e) {
            $refused = $e;
        }

        $this->assertSame(1, Backup::query()->where('virtual_machine_id', $machine->id)->where('state', BackupState::Restoring->value)->count());
        $this->assertSame(1, Backup::query()->where('virtual_machine_id', $machine->id)->whereNotNull('restore_task_id')->count(), 'One provider restore, not two.');
        $this->assertNotNull($refused);
        $this->assertSame('backup.restore_in_flight', $refused->errorCode());
        $this->assertSame(BackupState::Succeeded, $a->refresh()->state);
    }

    #[Test]
    public function a_restore_does_not_overwrite_a_verification_started_under_it(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $archive = $this->archive($customer, $machine, 72);
        $this->fake($machine)->pollsBeforeSettling = 1_000;

        $fired = false;
        ComputeCluster::retrieved(function () use (&$fired): void {
            if ($fired) {
                return;
            }
            $fired = true;
            app(VerifyStoredArchives::class)->execute();
        });

        $refused = null;

        try {
            app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine->fresh(), $machine->hostname);
        } catch (RestoreRefusedException $e) {
            $refused = $e;
        }

        $row = $archive->refresh();
        $this->assertSame(BackupState::Verifying, $row->state, 'The verification is still what this row is waiting on.');
        $this->assertNotNull($row->verification_task_id);
        $this->assertNull($row->restore_task_id);
        $this->assertNotNull($refused);
    }

    #[Test]
    public function a_stale_deletion_does_not_touch_an_archive_kept_and_being_restored(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $archive = $this->archive($customer, $machine, 72);
        $this->fake($machine)->pollsBeforeSettling = 1_000;

        app(RequestBackupDeletion::class)->execute($archive, RequestBackupDeletion::BY_CUSTOMER, $user);

        // The retention sweep's copy, read before the customer changed their mind.
        $stale = Backup::query()->findOrFail($archive->id);
        $this->assertSame(BackupState::DeleteRequested, $stale->state);

        app(RequestBackupDeletion::class)->cancel(Backup::query()->findOrFail($archive->id));
        app(RestoreServiceBackup::class)->execute(Backup::query()->findOrFail($archive->id), $machine, $machine->hostname);

        app(DeleteBackupAtProvider::class)->execute($stale);

        $row = $archive->refresh();
        $this->assertSame(BackupState::Restoring, $row->state);
        $this->assertSame(0, $row->deletion_attempts, 'The provider was never asked to delete it.');
    }

    #[Test]
    public function a_transition_from_a_state_the_row_has_left_is_refused_and_writes_nothing(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $archive = $this->archive($customer, $machine, 72);

        $stale = Backup::query()->findOrFail($archive->id);
        Backup::query()->findOrFail($archive->id)->transitionTo(BackupState::DeleteRequested, ['deletion_reason' => 'customer']);

        try {
            $stale->transitionTo(BackupState::Verifying, ['verification_task_id' => 'UPID:x']);
            $this->fail('A transition from a state the row has left must be refused.');
        } catch (IllegalBackupTransitionException $e) {
            $this->assertTrue($e->wasRaced());
        }

        $row = $archive->refresh();
        $this->assertSame(BackupState::DeleteRequested, $row->state);
        $this->assertNull($row->verification_task_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function archive(Customer $customer, VirtualMachine $machine, int $hoursAgo, array $overrides = []): Backup
    {
        return Backup::factory()->takenHoursAgo($hoursAgo)->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
            'provider_task_id' => 'UPID:fake:own-'.(++$this->made),
            ...$overrides,
        ]);
    }

    private function onceWhenUpdated(Backup $row, callable $then): void
    {
        $fired = false;
        Backup::updated(function (Backup $updated) use (&$fired, $row, $then): void {
            if ($fired || $updated->id !== $row->id) {
                return;
            }
            $fired = true;
            $then();
        });
    }

    private function fake(VirtualMachine $machine): FakeBackupProvider
    {
        $provider = app(BackupProviderFactory::class)->for($machine->cluster()->firstOrFail());
        $this->assertInstanceOf(FakeBackupProvider::class, $provider);

        return $provider;
    }
}
