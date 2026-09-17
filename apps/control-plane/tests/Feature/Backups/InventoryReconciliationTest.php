<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\ReconcileBackupInventory;
use Lynomia\Modules\Backups\Application\Actions\VerifyStoredArchives;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftKind;
use Lynomia\Modules\Provisioning\Domain\Enums\DriftSeverity;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ResourceDrift;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backups\Doubles\SelfVerifyingDatastore;
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

    /*
    |--------------------------------------------------------------------------
    | The verification verdict, on a provider that cannot be asked
    |--------------------------------------------------------------------------
    |
    | Proxmox Backup Server verifies on its own schedule and exposes no
    | endpoint to start one, so for the only backup adapter that can run in
    | production the listing is the single route by which a verdict ever
    | reaches the platform. Before this it reached it by no route at all:
    | listBackups() computed the verdict per archive and nothing read it.
    |
    */

    #[Test]
    public function the_three_verdicts_a_datastore_can_report_are_adopted_as_three_different_things(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $readBack = $this->backupFor($customer, $machine, 'vzdump-qemu-clean.vma.zst');
        $corrupt = $this->backupFor($customer, $machine, 'vzdump-qemu-corrupt.vma.zst');
        $unchecked = $this->backupFor($customer, $machine, 'vzdump-qemu-unchecked.vma.zst');

        $this->swapDatastore($machine, new SelfVerifyingDatastore([
            'vzdump-qemu-clean.vma.zst' => true,
            'vzdump-qemu-corrupt.vma.zst' => false,
            'vzdump-qemu-unchecked.vma.zst' => null,
        ]));

        $result = app(ReconcileBackupInventory::class)->execute();

        // Two verdicts to adopt. The third is not a verdict.
        $this->assertSame(2, $result['verdicts']);

        $this->assertTrue($readBack->refresh()->verified);
        $this->assertNotNull($readBack->verified_at);

        // Recorded as false, which is what the unverified alert reads. Not
        // failed, not deleted: what to do about a corrupt archive is a
        // person's call.
        $this->assertFalse($corrupt->refresh()->verified);
        $this->assertNotNull($corrupt->verified_at);
        $this->assertSame(BackupState::Succeeded, $corrupt->state);

        // Nothing written. An unchecked archive reported as a broken one is
        // the most misleading thing this sweep could do.
        $this->assertNull($unchecked->refresh()->verified);
        $this->assertNull($unchecked->verified_at);

        // No disagreement: every archive the platform knows about is present.
        $this->assertSame(0, $result['drifts']);
    }

    #[Test]
    public function a_verdict_already_known_is_not_rewritten_on_every_later_sweep(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = $this->backupFor($customer, $machine, 'vzdump-qemu-clean.vma.zst');

        $this->swapDatastore($machine, new SelfVerifyingDatastore(['vzdump-qemu-clean.vma.zst' => true]));

        app(ReconcileBackupInventory::class)->execute();
        $firstKnownAt = $backup->refresh()->verified_at;

        $this->travel(2)->hours();
        $second = app(ReconcileBackupInventory::class)->execute();

        // `verified_at` means "when this platform first knew", and a sweep
        // every five minutes must not keep moving it to now.
        $this->assertSame(0, $second['verdicts']);
        $this->assertTrue($backup->refresh()->verified);
        $this->assertEquals($firstKnownAt, $backup->verified_at);
    }

    #[Test]
    public function a_verdict_already_known_is_not_erased_when_the_datastore_stops_reporting_one(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = $this->backupFor($customer, $machine, 'vzdump-qemu-clean.vma.zst');

        $this->swapDatastore($machine, new SelfVerifyingDatastore(['vzdump-qemu-clean.vma.zst' => true]));
        app(ReconcileBackupInventory::class)->execute();

        $this->assertTrue($backup->refresh()->verified);
        $knownAt = $backup->verified_at;

        /*
         * The same archive, listed again, with no verification record on it.
         * PBS prunes verification results, and a datastore that has forgotten
         * checking something is not a datastore saying it failed — nor is it
         * grounds to forget that this platform once saw a clean read.
         *
         * Without the null guard the row would go from "read back cleanly" to
         * "never checked", which is the one direction a verdict must never
         * travel: the customer's backup would quietly stop being verified.
         */
        $this->swapDatastore($machine, new SelfVerifyingDatastore(['vzdump-qemu-clean.vma.zst' => null]));
        $result = app(ReconcileBackupInventory::class)->execute();

        $this->assertSame(0, $result['verdicts']);
        $this->assertTrue($backup->refresh()->verified);
        $this->assertEquals($knownAt, $backup->verified_at);
    }

    #[Test]
    public function a_datastore_that_cannot_be_asked_to_verify_is_left_alone_rather_than_asked_and_refused(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = $this->backupFor($customer, $machine, 'vzdump-qemu-unchecked.vma.zst');
        $datastore = new SelfVerifyingDatastore(['vzdump-qemu-unchecked.vma.zst' => null]);
        $this->swapDatastore($machine, $datastore);

        $sweep = app(VerifyStoredArchives::class)->execute();

        /*
         * The row is untouched, and that is the whole point.
         *
         * The version that did not ask supportsVerification() first was
         * bounded by the attempt limit, so it refused each archive three
         * times rather than for ever — but each of those refusals raised the
         * attempt counter and wrote the provider's refusal into
         * `failure_reason`, the column a person reads as the verdict on the
         * archive. On the one adapter that can run in production that was
         * every backup the platform would ever take, each carrying a sentence
         * about verification failing.
         */
        $this->assertSame(0, $datastore->verificationsAttempted);
        $this->assertSame(0, $backup->refresh()->verification_attempts);
        $this->assertNull($backup->failure_reason);
        $this->assertNull($backup->verification_requested_at);
        $this->assertSame(BackupState::Succeeded, $backup->state);

        // Counted as neither done nor failed: looked at, and deliberately not
        // acted on. A healthy platform must not report standing failures.
        $this->assertSame(1, $sweep->considered);
        $this->assertSame(0, $sweep->settled);
        $this->assertSame(0, $sweep->failed);
        $this->assertSame(1, $sweep->skipped);
    }

    private function swapDatastore(mixed $machine, SelfVerifyingDatastore $datastore): void
    {
        $this->app->singleton(BackupProviderFactory::class);
        app(BackupProviderFactory::class)->swap($machine->cluster()->firstOrFail(), $datastore);
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
