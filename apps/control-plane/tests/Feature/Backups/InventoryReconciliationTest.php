<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\ReconcileBackupInventory;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backups\Doubles\StubbornBackupProvider;
use Tests\Feature\Backups\Doubles\UnreachableBackupProvider;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * Asking a datastore what it is actually holding.
 *
 * `listBackups()` was implemented, tested against recorded exchanges, and
 * called by nothing — so the platform's belief that a customer had four
 * restorable backups rested entirely on its own record of having taken them.
 * A customer finds out that belief was wrong while they are restoring.
 */
final class InventoryReconciliationTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
    }

    #[Test]
    public function a_backup_the_datastore_no_longer_has_is_reported_as_critical(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        // The row says the archive is restorable; the datastore holds nothing.
        $backup = $this->backupFor($customer, $machine, 'vzdump-qemu-gone.vma.zst');

        $result = app(ReconcileBackupInventory::class)->execute();

        $this->assertSame(1, $result['drifts']);

        $drift = ResourceDrift::query()->sole();
        $this->assertSame(DriftKind::MissingAtProvider, $drift->kind);
        // A customer finds out at the worst possible moment, which is what
        // makes this critical rather than a warning.
        $this->assertSame(DriftSeverity::Critical, $drift->severity);
        $this->assertSame('vzdump-qemu-gone.vma.zst', $drift->provider_reference);

        // Reported, not corrected. The row still says what the platform
        // believed, because pretending otherwise loses the disagreement.
        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
    }

    #[Test]
    public function an_archive_nobody_took_is_reported_and_never_adopted(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $known = $this->backupFor($customer, $machine, 'vzdump-qemu-known.vma.zst');

        $this->app->singleton(BackupProviderFactory::class);
        app(BackupProviderFactory::class)->swap(
            $machine->cluster()->firstOrFail(),
            new StubbornBackupProvider('vzdump-qemu-known.vma.zst', 'vzdump-qemu-stranger.vma.zst'),
        );

        app(ReconcileBackupInventory::class)->execute();

        $drift = ResourceDrift::query()->sole();
        $this->assertSame(DriftKind::OrphanAtProvider, $drift->kind);
        $this->assertSame('vzdump-qemu-stranger.vma.zst', $drift->provider_reference);

        // An archive whose provenance nobody knows is not offered to a
        // customer as their backup, and no row is created for it.
        $this->assertSame(1, Backup::query()->count());
        $this->assertSame((string) $known->getKey(), (string) Backup::query()->sole()->getKey());
    }

    #[Test]
    public function a_deletion_the_provider_finished_later_is_settled_rather_than_reported(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = $this->backupFor($customer, $machine, 'vzdump-qemu-slow.vma.zst');
        $backup->transitionTo(BackupState::DeleteRequested, ['deletion_requested_at' => now()->subHour()]);
        $backup->refresh()->transitionTo(BackupState::Deleting, []);

        // The datastore no longer lists it: the prune queued behind a
        // verification has finally run. That is the answer the platform was
        // waiting for, not a disagreement.
        $result = app(ReconcileBackupInventory::class)->execute();

        $this->assertSame(1, $result['settled']);
        $this->assertSame(0, $result['drifts']);

        $after = $backup->refresh();
        $this->assertSame(BackupState::Deleted, $after->state);
        $this->assertNotNull($after->provider_deleted_at);
    }

    #[Test]
    public function an_archive_that_came_back_after_deletion_is_reported(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = $this->backupFor($customer, $machine, 'vzdump-qemu-undead.vma.zst');
        $backup->transitionTo(BackupState::DeleteRequested, ['deletion_requested_at' => now()->subHour()]);
        $backup->refresh()->transitionTo(BackupState::Deleting, []);
        $backup->refresh()->transitionTo(BackupState::Deleted, ['provider_deleted_at' => now()->subMinutes(5)]);

        $this->app->singleton(BackupProviderFactory::class);
        app(BackupProviderFactory::class)->swap(
            $machine->cluster()->firstOrFail(),
            new StubbornBackupProvider('vzdump-qemu-undead.vma.zst'),
        );

        app(ReconcileBackupInventory::class)->execute();

        // It is occupying space nobody is accounting for.
        $drift = ResourceDrift::query()->sole();
        $this->assertSame(DriftKind::OrphanAtProvider, $drift->kind);
        $this->assertTrue($drift->kind->isBillingRelevant());
    }

    #[Test]
    public function a_datastore_that_will_not_answer_raises_nothing(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->backupFor($customer, $machine, 'vzdump-qemu-quiet.vma.zst');

        $this->app->singleton(BackupProviderFactory::class);
        app(BackupProviderFactory::class)->swap(
            $machine->cluster()->firstOrFail(),
            new UnreachableBackupProvider,
        );

        $result = app(ReconcileBackupInventory::class)->execute();

        /*
         * A datastore that will not answer has not lost anything. Recording
         * drift on an unread listing would raise a critical alarm every time a
         * provider was briefly unreachable, and teach an operator to ignore
         * the one that matters.
         */
        $this->assertSame(0, $result['drifts']);
        $this->assertSame(0, ResourceDrift::query()->count());
    }

    private function backupFor(mixed $customer, mixed $machine, string $archiveId): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
            'node_name' => 'pve-01',
            'datastore' => 'pbs-test',
            'archive_id' => $archiveId,
            'provider_task_id' => 'UPID:fake:'.uniqid(),
        ]);
    }
}
