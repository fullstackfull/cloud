<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Application\Actions\ReconcileRunningBackups;
use Lynomia\Modules\Backups\Application\Actions\VerifyStoredArchives;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Backups\Infrastructure\Providers\FakeBackupProvider;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * Three defects found beside F-09, each in the path an archive takes after it
 * was stored.
 *
 *  - The verification sweep met an archive whose cluster was gone, tried to
 *    move it `succeeded → needs_review` (a transition the table forbids),
 *    threw, counted nothing, and met the same archive first on every sweep
 *    after — so with enough such rows no other archive was ever verified.
 *  - A restored archive was shown as restorable (`BackupState::isRestorable`)
 *    and refused by the server's restore guard, which admitted only
 *    `succeeded` and `verified`.
 *  - A verification wrote its task handle over `provider_task_id`, the
 *    backup's own handle, which the restore path documents must never be
 *    overwritten.
 */
final class TheSweepAndTheGuardKeepTheArchiveIntactTest extends VpsApiTestCase
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
    public function an_archive_whose_cluster_is_gone_is_counted_and_does_not_starve_the_sweep(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        // Older, so the sweep's ordering meets it first.
        $orphan = $this->archiveFor($customer, $machine, hoursAgo: 96, overrides: ['cluster_id' => null]);
        $good = $this->archiveFor($customer, $machine, hoursAgo: 48);

        app(VerifyStoredArchives::class)->execute(limit: 1);
        app(VerifyStoredArchives::class)->execute(limit: 1);

        $orphan->refresh();
        $this->assertSame(BackupState::Succeeded, $orphan->state, 'Still a backup the platform took; nothing proved otherwise.');
        $this->assertSame(1, $orphan->verification_attempts, 'The attempt is counted, so the attempt limit ends it.');
        $this->assertNotNull($orphan->verification_requested_at);
        $this->assertStringContainsString('cluster', (string) $orphan->failure_reason);

        $this->assertSame(BackupState::Verifying, $good->refresh()->state, 'The next archive is reached.');

        // And it leaves the sweep's scope at the limit rather than being asked for ever.
        app(VerifyStoredArchives::class)->execute();
        app(VerifyStoredArchives::class)->execute();
        app(VerifyStoredArchives::class)->execute();
        $this->assertSame(3, $orphan->refresh()->verification_attempts);
        $this->assertFalse(Backup::query()->awaitingVerification(3)->whereKey($orphan->id)->exists());
    }

    #[Test]
    public function a_restored_archive_can_be_restored_again_and_says_so(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->archiveFor($customer, $machine, hoursAgo: 48);
        $url = '/api/v1/vps/'.$machine->id.'/backups/'.$backup->id.'/restore';

        $this->actingAs($user)->postJson($url, ['confirmation' => $machine->hostname])->assertStatus(202);
        app(ReconcileRunningBackups::class)->execute();
        app(ReconcileRunningBackups::class)->execute();
        $this->assertSame(BackupState::Restored, $backup->refresh()->state);

        $listed = collect($this->actingAs($user)->getJson('/api/v1/vps/'.$machine->id.'/backups')->assertOk()->json('data'))
            ->keyBy('id');
        $this->assertTrue($listed[$backup->id]['is_restorable']);

        // What the portal offers, the server accepts.
        $this->actingAs($user)->postJson($url, ['confirmation' => $machine->hostname])->assertStatus(202);
        $this->assertSame(BackupState::Restoring, $backup->refresh()->state);
    }

    #[Test]
    public function a_verification_leaves_the_backups_own_task_id_alone(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->archiveFor($customer, $machine, hoursAgo: 48);
        $own = $backup->provider_task_id;

        app(VerifyStoredArchives::class)->execute();

        $backup->refresh();
        $this->assertSame(BackupState::Verifying, $backup->state);
        $this->assertSame($own, $backup->provider_task_id, 'The backup\'s own handle is not the verification\'s to overwrite.');
        $this->assertNotNull($backup->verification_task_id);
        $this->assertNotSame($own, $backup->verification_task_id);

        // And the poller settles the verification on its own handle.
        app(ReconcileRunningBackups::class)->execute();
        app(ReconcileRunningBackups::class)->execute();

        $backup->refresh();
        $this->assertSame(BackupState::Verified, $backup->state);
        $this->assertTrue($backup->verified);
        $this->assertSame($own, $backup->provider_task_id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function archiveFor(Customer $customer, VirtualMachine $machine, int $hoursAgo, array $overrides = []): Backup
    {
        $this->assertInstanceOf(
            FakeBackupProvider::class,
            app(BackupProviderFactory::class)->for($machine->cluster()->firstOrFail()),
        );

        return Backup::factory()->takenHoursAgo($hoursAgo)->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'cluster_id' => $machine->cluster_id,
            'provider_task_id' => 'UPID:fake:own-'.(++$this->made),
            ...$overrides,
        ]);
    }
}
