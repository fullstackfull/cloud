<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Audit\Infrastructure\Models\AuditEntry;
use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Catalog\Infrastructure\Models\Plan;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * A customer destroying one copy of their own data.
 *
 * The endpoint records a decision and calls no provider, so most of these are
 * about the refusals and about what the row says in between. The one claim
 * that must never be made early is `deleted`.
 */
final class DeletingABackupTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
    }

    #[Test]
    public function a_confirmed_deletion_is_recorded_and_nothing_is_gone_yet(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->availableBackup($customer, $machine);

        $this->actingAs($user)
            ->deleteJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertOk()
            ->assertJsonPath('data.state', BackupState::DeleteRequested->value)
            ->assertJsonPath('data.is_being_deleted', true)
            // The archive is still on the datastore. A screen that said
            // otherwise would be the same false claim as one calling a backup
            // succeeded because a job was enqueued.
            ->assertJsonPath('data.deleted_at', null);

        $marked = $backup->refresh();
        $this->assertSame(BackupState::DeleteRequested, $marked->state);
        $this->assertNotNull($marked->deletion_requested_at);
        $this->assertSame('customer', $marked->deletion_reason);
        $this->assertSame((string) $user->getKey(), $marked->deletion_requested_by_user_id);
        $this->assertNull($marked->provider_deleted_at);

        $entry = AuditEntry::query()->sole();
        $this->assertSame(AuditAction::BackupDeletionRequested, $entry->action);
    }

    #[Test]
    public function calling_a_deletion_off_is_written_into_the_trail_beside_the_request(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->availableBackup($customer, $machine);

        $this->actingAs($user)
            ->deleteJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertOk();

        $this->actingAs($user)
            ->postJson($this->url($machine, $backup).'/keep')
            ->assertOk()
            ->assertJsonPath('data.state', BackupState::Succeeded->value)
            ->assertJsonPath('data.is_being_deleted', false);

        /*
         * Both halves, in order. A trail that recorded the request and not the
         * reprieve would leave the next person reading it certain the archive
         * is gone — and looking for a reason it is still on the datastore.
         */
        $this->assertSame(
            [AuditAction::BackupDeletionRequested, AuditAction::BackupDeletionCancelled],
            AuditEntry::query()->orderBy('created_at')->orderBy('id')->pluck('action')->all(),
        );
    }

    #[Test]
    public function the_wrong_phrase_deletes_nothing(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->availableBackup($customer, $machine);

        $this->actingAs($user)
            ->deleteJson($this->url($machine, $backup), ['confirmation' => 'not-the-hostname'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'backup.deletion_not_confirmed');

        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
        $this->assertSame(0, AuditEntry::query()->count());
    }

    #[Test]
    public function a_backup_being_restored_from_cannot_be_deleted(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->availableBackup($customer, $machine);
        $backup->transitionTo(BackupState::Restoring, []);

        // Deleting it mid-restore leaves a machine half-written from a source
        // that no longer exists.
        $this->actingAs($user)
            ->deleteJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.restore_in_progress');
    }

    #[Test]
    public function a_backup_still_being_written_cannot_be_deleted(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $backup = Backup::factory()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'state' => BackupState::Running,
        ]);

        $this->actingAs($user)
            ->deleteJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.still_in_flight');
    }

    #[Test]
    public function asking_twice_is_refused_rather_than_marked_twice(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->availableBackup($customer, $machine);

        $payload = ['confirmation' => $machine->hostname];

        $this->actingAs($user)->deleteJson($this->url($machine, $backup), $payload)->assertOk();
        $this->actingAs($user)->deleteJson($this->url($machine, $backup), $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.deletion_already_requested');

        // One decision, one audit entry. A second would read as two people
        // having asked.
        $this->assertSame(1, AuditEntry::query()->count());
    }

    #[Test]
    public function a_plan_that_forbids_deletion_refuses_it(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->availableBackup($customer, $machine);

        $this->planFor($machine, ['backup_customer_may_delete' => false]);

        // The plan sells retention as a guarantee. An account whose backups
        // are its evidence must not be able to destroy that evidence from a
        // web page.
        $this->actingAs($user)
            ->deleteJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'backup.deletion_not_permitted');

        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
    }

    #[Test]
    public function a_backup_held_through_a_retention_window_refuses_deletion(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);
        $backup = $this->availableBackup($customer, $machine);
        $backup->forceFill(['protected_until' => now()->addDays(20)])->save();

        $this->actingAs($user)
            ->deleteJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'backup.protected');
    }

    #[Test]
    public function a_technical_member_may_rebuild_a_machine_and_not_destroy_its_backups(): void
    {
        [$customer] = $this->accountWithOwner();
        $engineer = $this->memberOf($customer, CustomerRole::Technical);
        $machine = $this->machineFor($customer);
        $backup = $this->availableBackup($customer, $machine);

        $this->assertTrue(CustomerRole::Technical->can('service.manage'));
        $this->assertFalse(CustomerRole::Technical->can('service.destroy'));

        $this->actingAs($engineer)
            ->deleteJson($this->url($machine, $backup), ['confirmation' => $machine->hostname])
            ->assertStatus(403);

        $this->assertSame(BackupState::Succeeded, $backup->refresh()->state);
    }

    #[Test]
    public function another_customers_backup_is_not_found(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $myMachine = $this->machineFor($mine);
        $theirMachine = $this->machineFor($theirs);
        $theirBackup = $this->availableBackup($theirs, $theirMachine);

        $this->actingAs($me)
            ->deleteJson($this->url($myMachine, $theirBackup), ['confirmation' => $myMachine->hostname])
            ->assertNotFound();

        $this->assertSame(BackupState::Succeeded, $theirBackup->refresh()->state);
    }

    private function availableBackup(mixed $customer, mixed $machine): Backup
    {
        return Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $resources
     */
    private function planFor(mixed $machine, array $resources): void
    {
        $plan = Plan::factory()->create(['resources' => $resources]);

        Service::query()->whereKey($machine->service_id)->update(['plan_id' => $plan->getKey()]);
    }

    private function url(mixed $machine, Backup $backup): string
    {
        return '/api/v1/vps/'.$machine->id.'/backups/'.$backup->id;
    }
}
