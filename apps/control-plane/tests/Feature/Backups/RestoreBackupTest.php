<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\BackupProviderFactory;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Backups\Doubles\UnreachableBackupProvider;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * Restoring a backup: the most destructive thing this API offers a customer.
 *
 * The provider call, the Restoring state and the poller that watches the task
 * were all built and had no caller. What was missing was the confirmation
 * flow, so these tests are mostly about the refusals — the happy path is one
 * test and the ways the platform declines are seven.
 */
final class RestoreBackupTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
    }

    #[Test]
    public function a_confirmed_restore_starts_and_is_recorded(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        $this->actingAs($user)
            ->postJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            // Accepted, not done: answering 200 would tell a customer their
            // machine is back while the disks are still being written.
            ->assertStatus(202)
            ->assertJsonPath('data.state', BackupState::Restoring->value);

        $restored = $backup->refresh();

        $this->assertSame(BackupState::Restoring, $restored->state);
        $this->assertNotNull($restored->restore_started_at);
        $this->assertNotNull($restored->restore_task_id);
        $this->assertSame((string) $user->getKey(), $restored->restored_by_user_id);

        // The backup's own task id survives. It is what finds the archive if
        // the restore goes wrong, which is exactly when it is needed.
        $this->assertSame('UPID:fake:done', $restored->provider_task_id);
        $this->assertNotSame($restored->provider_task_id, $restored->restore_task_id);

        $entry = AuditEntry::query()->sole();
        $this->assertSame(AuditAction::BackupRestored, $entry->action);
        $this->assertSame((string) $customer->getKey(), $entry->customer_id);
    }

    #[Test]
    public function the_wrong_hostname_restores_nothing(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        $this->actingAs($user)
            ->postJson($this->url($machine, $backup), ['confirmation' => $machine->hostname.'x'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'backup.restore_confirmation_mismatch');

        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function the_confirmation_is_not_case_folded(): void
    {
        /*
         * A hostname is case-insensitive in DNS. The confirmation is not a
         * lookup — it is evidence that a person read the screen — so it is
         * compared exactly.
         */
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-kw-01');
        $backup = $this->completedBackupFor($customer, $machine);

        $this->actingAs($user)
            ->postJson($this->url($machine, $backup), ['confirmation' => 'WEB-KW-01'])
            ->assertStatus(422);

        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
    }

    #[Test]
    public function a_backup_that_never_finished_cannot_be_restored(): void
    {
        // Its archive is still being written. Restoring from it would put a
        // half-copied disk over a working machine.
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = Backup::factory()->running()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
        ]);

        $this->actingAs($user)
            ->postJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'backup.not_restorable');
    }

    #[Test]
    public function a_second_restore_is_refused_while_one_is_running(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'state' => BackupState::Restoring,
            // Distinct from the factory's default: the table carries a unique
            // index on (provider, provider_task_id), which is itself the
            // reason a restore's task id lives in its own column.
            'provider_task_id' => 'UPID:fake:earlier',
        ]);

        $second = $this->completedBackupFor($customer, $machine);

        $this->actingAs($user)
            ->postJson($this->url($machine, $second), ['confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.restore_in_flight');
    }

    #[Test]
    public function a_suspended_service_cannot_be_restored(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, status: ServiceStatus::Suspended);
        $backup = $this->completedBackupFor($customer, $machine);

        $this->actingAs($user)
            ->postJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertStatus(422);
    }

    #[Test]
    public function another_tenants_backup_is_not_found_rather_than_forbidden(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        $myMachine = $this->machineFor($mine);

        [$theirs] = $this->accountWithOwner();
        $theirMachine = $this->machineFor($theirs);
        $theirBackup = $this->completedBackupFor($theirs, $theirMachine);

        /*
         * 404 and not 403: a forbidden answer confirms that the id exists,
         * which is how an id space gets enumerated.
         */
        $this->actingAs($me)
            ->postJson('/api/v1/vps/'.$myMachine->id.'/backups/'.$theirBackup->id.'/restore', [
                'confirmation' => $myMachine->hostname,
            ])
            ->assertNotFound();

        $this->actingAs($me)
            ->postJson($this->url($theirMachine, $theirBackup), ['confirmation' => $theirMachine->hostname])
            ->assertNotFound();

        $this->assertSame(BackupState::Succeeded, $theirBackup->refresh()->state);
    }

    #[Test]
    public function a_member_without_service_manage_cannot_restore(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        // Billing can pay the invoice for the machine and cannot touch its disks.
        $viewer = $this->memberOf($customer, CustomerRole::Billing);

        $this->actingAs($viewer)
            ->postJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertForbidden();

        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);

        // And the owner still can, so the refusal above is about the role and
        // not about something else being wrong with the request.
        $this->actingAs($owner)
            ->postJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertStatus(202);
    }

    #[Test]
    public function a_provider_that_stops_answering_leaves_the_restore_for_a_person(): void
    {
        /*
         * The rule the whole platform follows, and it matters most here. A
         * restore call that does not answer may be running right now, writing
         * to the customer's disks. Marking it failed invites a retry that
         * starts a second restore over a disk the first is halfway through.
         */
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->completedBackupFor($customer, $machine);

        $this->app->singleton(BackupProviderFactory::class);
        app(BackupProviderFactory::class)->swap(
            $machine->cluster()->firstOrFail(),
            new UnreachableBackupProvider,
        );

        $this->actingAs($user)
            ->postJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertStatus(202);

        $reviewed = $backup->refresh();

        $this->assertSame(BackupState::NeedsReview, $reviewed->state);
        $this->assertNotNull($reviewed->failure_reason);

        // Stopped, not retried: nothing may start a second restore.
        $this->assertNull($reviewed->restore_task_id);
    }

    private function completedBackupFor(mixed $customer, mixed $machine): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
        ]);
    }

    private function url(mixed $machine, Backup $backup): string
    {
        return '/api/v1/vps/'.$machine->id.'/backups/'.$backup->id.'/restore';
    }
}
