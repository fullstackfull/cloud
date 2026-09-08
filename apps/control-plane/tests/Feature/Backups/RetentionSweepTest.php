<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\EnforceBackupRetention;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backups\Doubles\StubbornBackupProvider;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * The sweep that keeps a datastore from growing for ever.
 *
 * The claim under test throughout: a backup is not gone because the platform
 * asked for it to go.
 */
final class RetentionSweepTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
        // No grace in most of these: the pause between marking and acting is
        // its own test below, and every other assertion here is about what the
        // sweep decides rather than about when.
        config()->set('backups.deletion_grace_hours', 0);
    }

    #[Test]
    public function an_expired_backup_is_marked_and_then_confirmed_gone(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = $this->availableBackup($customer, $machine, expiresAt: now()->subDay());

        // The fake datastore holds nothing, so the archive is absent from the
        // listing after the delete — which is what "gone" means here.
        $result = app(EnforceBackupRetention::class)->execute();

        $this->assertSame(1, $result['marked']);
        $this->assertSame(1, $result['deleted']);

        $swept = $backup->refresh();
        $this->assertSame(BackupState::Deleted, $swept->state);
        $this->assertSame('retention', $swept->deletion_reason);
        $this->assertNotNull($swept->provider_deleted_at);

        // The row survives. "Where is my backup from March" has an answer.
        $this->assertDatabaseHas('backups', ['id' => $backup->getKey()]);
    }

    #[Test]
    public function a_backup_inside_its_retention_window_is_left_alone(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = $this->availableBackup($customer, $machine, expiresAt: now()->addDays(5));

        $result = app(EnforceBackupRetention::class)->execute();

        $this->assertSame(0, $result['marked']);
        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
    }

    #[Test]
    public function a_plan_ceiling_drops_the_oldest_and_never_the_newest(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->planFor($machine, ['backup_max_retained' => 2]);

        $oldest = $this->availableBackup($customer, $machine, createdAt: now()->subDays(3));
        $middle = $this->availableBackup($customer, $machine, createdAt: now()->subDays(2));
        $newest = $this->availableBackup($customer, $machine, createdAt: now()->subDay());

        app(EnforceBackupRetention::class)->execute();

        // The most recent backup is the one somebody restoring from a disaster
        // reaches for. It is never the one a ceiling removes.
        $this->assertSame(BackupState::Deleted, $oldest->refresh()->state);
        $this->assertSame(BackupState::Succeeded, $middle->refresh()->state);
        $this->assertSame(BackupState::Succeeded, $newest->refresh()->state);
    }

    #[Test]
    public function a_provider_that_accepts_and_keeps_the_archive_is_not_called_deleted(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = $this->availableBackup($customer, $machine, expiresAt: now()->subDay());
        $backup->forceFill(['archive_id' => 'vzdump-qemu-stubborn.vma.zst'])->save();

        $stubborn = new StubbornBackupProvider('vzdump-qemu-stubborn.vma.zst');

        $this->app->singleton(BackupProviderFactory::class);
        app(BackupProviderFactory::class)->swap($machine->cluster()->firstOrFail(), $stubborn);

        $result = app(EnforceBackupRetention::class)->execute();

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $stubborn->deletionsAccepted);

        // Accepted is not deleted. The row says so.
        $after = $backup->refresh();
        $this->assertSame(BackupState::Deleting, $after->state);
        $this->assertNull($after->provider_deleted_at);
        $this->assertSame(1, $after->deletion_attempts);
    }

    #[Test]
    public function an_archive_that_will_not_go_ends_up_in_front_of_a_person(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        config()->set('backups.deletion_attempts', 2);

        $backup = $this->availableBackup($customer, $machine, expiresAt: now()->subDay());
        $backup->forceFill(['archive_id' => 'vzdump-qemu-stubborn.vma.zst'])->save();

        $this->app->singleton(BackupProviderFactory::class);
        app(BackupProviderFactory::class)->swap(
            $machine->cluster()->firstOrFail(),
            new StubbornBackupProvider('vzdump-qemu-stubborn.vma.zst'),
        );

        $sweep = app(EnforceBackupRetention::class);
        $sweep->execute();
        $sweep->execute();

        // An archive that will not delete is a datastore filling up, which is
        // a person's problem rather than a retry's.
        $after = $backup->refresh();
        $this->assertSame(BackupState::NeedsReview, $after->state);
        $this->assertStringContainsString('still listed', (string) $after->failure_reason);
    }

    #[Test]
    public function the_grace_period_gives_a_customer_time_to_change_their_mind(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        config()->set('backups.deletion_grace_hours', 1);

        $backup = $this->availableBackup($customer, $machine, expiresAt: now()->subDay());

        // First pass marks it and does not act: the deletion was decided
        // seconds ago and the hour has not elapsed.
        $first = app(EnforceBackupRetention::class)->execute();
        $this->assertSame(1, $first['marked']);
        $this->assertSame(0, $first['deleted']);
        $this->assertSame(BackupState::DeleteRequested, $backup->refresh()->state);

        // An hour later it goes.
        $this->travel(2)->hours();
        $second = app(EnforceBackupRetention::class)->execute();
        $this->assertSame(1, $second['deleted']);
        $this->assertSame(BackupState::Deleted, $backup->refresh()->state);
    }

    #[Test]
    public function a_held_backup_survives_its_own_expiry(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = $this->availableBackup($customer, $machine, expiresAt: now()->subDay());
        $backup->forceFill(['protected_until' => now()->addDays(20)])->save();

        $result = app(EnforceBackupRetention::class)->execute();

        // A customer who cancelled by mistake on the 1st and asks on the 20th
        // still has something to restore. The sweep skips it silently rather
        // than filling an operator's screen with a row behaving correctly.
        $this->assertSame(0, $result['marked']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
    }

    #[Test]
    public function a_backup_being_restored_from_is_skipped_rather_than_failed(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = $this->availableBackup($customer, $machine, expiresAt: now()->subDay());
        $backup->transitionTo(BackupState::Restoring, []);

        $result = app(EnforceBackupRetention::class)->execute();

        // Restoring is not an available state, so the sweep does not see it at
        // all — and the machine keeps the source it is being written from.
        $this->assertSame(0, $result['marked']);
        $this->assertSame(BackupState::Restoring, $backup->refresh()->state);
    }

    private function availableBackup(
        mixed $customer,
        mixed $machine,
        mixed $expiresAt = null,
        mixed $createdAt = null,
    ): Backup {
        /*
         * The machine's own cluster, not a fresh one from the factory. The
         * deletion path asks *the backup's* cluster for a provider, so a row
         * pointing at some other cluster would be handed a provider no test
         * has swapped — and the assertion would pass for reasons that have
         * nothing to do with the code under test.
         *
         * The task id is made unique for the same kind of reason: the table
         * holds one row per (provider, task), and three fixtures sharing the
         * factory's default collide on it.
         */
        $backup = Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
            'node_name' => 'pve-01',
            'datastore' => 'pbs-test',
            'provider_task_id' => 'UPID:fake:'.uniqid(),
            'expires_at' => $expiresAt,
        ]);

        if ($createdAt !== null) {
            $backup->forceFill(['created_at' => $createdAt])->save();
        }

        return $backup->refresh();
    }

    /**
     * @param  array<string, mixed>  $resources
     */
    private function planFor(mixed $machine, array $resources): void
    {
        $plan = Plan::factory()->create(['resources' => $resources]);

        Service::query()->whereKey($machine->service_id)->update(['plan_id' => $plan->getKey()]);
    }
}
