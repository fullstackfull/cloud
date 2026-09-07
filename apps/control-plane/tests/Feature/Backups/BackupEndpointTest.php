<?php

declare(strict_types=1);

namespace Tests\Feature\Backups;

use Lynomia\Modules\Backups\Domain\Enums\BackupState;
use Lynomia\Modules\Backups\Infrastructure\Models\Backup;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\Customer;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\VpsApiTestCase;

/**
 * The customer-facing backup endpoints.
 *
 * What is asserted here beyond the happy path: another tenant's backup id
 * matches nothing, a read-only member cannot spend datastore space, and the
 * payload names none of the platform's infrastructure — not the datastore, not
 * the node, not the provider's task id. A customer needs none of those to know
 * whether their data is safe, and each of them tells them something about the
 * machines their neighbours are on.
 */
final class BackupEndpointTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('billing.providers.backup', 'fake');
    }

    #[Test]
    public function a_customer_sees_their_own_machines_backups(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/backups')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.state', 'succeeded')
            ->assertJsonPath('data.0.is_restorable', true);
    }

    #[Test]
    public function the_payload_names_no_part_of_the_platforms_infrastructure(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'virtual_machine_id' => $machine->id,
            'datastore' => 'pbs-kw-01',
            'node_name' => 'pve-kw-07',
            'provider_task_id' => 'UPID:pve-kw-07:0000A1B2',
        ]);

        $body = (string) $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/backups')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('pbs-kw-01', $body);
        $this->assertStringNotContainsString('pve-kw-07', $body);
        $this->assertStringNotContainsString('UPID:', $body);
    }

    #[Test]
    public function another_tenants_machine_is_not_found_rather_than_refused(): void
    {
        [, $user] = $this->accountWithOwner();

        $stranger = Customer::factory()->create();
        $theirs = $this->machineFor($stranger);

        // 404 and not 403: a 403 confirms the id exists, which over a loop is
        // an inventory of the platform's machines.
        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$theirs->id.'/backups')
            ->assertNotFound();
    }

    #[Test]
    public function a_backup_belonging_to_another_machine_is_not_found(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $machine = $this->machineFor($customer);
        $other = $this->machineFor($customer, hostname: 'second-machine');

        $backup = Backup::factory()->succeeded()->create([
            'customer_id' => $customer->id,
            'service_id' => $other->service_id,
            'virtual_machine_id' => $other->id,
        ]);

        // The customer owns both, so this is not a tenancy question: it is
        // whether the path's scope is real. Reading a backup through the wrong
        // machine would mean the nesting decorates rather than scopes.
        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/backups/'.$backup->id)
            ->assertNotFound();
    }

    #[Test]
    public function taking_a_backup_answers_accepted_and_not_created(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        config()->set('backups.datastores.'.$machine->cluster()->firstOrFail()->slug, 'pbs-test-01');

        $this->actingAs($user)
            ->postJson('/api/v1/vps/'.$machine->id.'/backups', ['mode' => 'snapshot'])
            // 202: the row exists and the backup does not. A 201 would say the
            // thing was created, which is the claim this module exists not to
            // make early.
            ->assertStatus(202)
            ->assertJsonPath('data.state', BackupState::Running->value)
            ->assertJsonPath('data.is_in_flight', true)
            ->assertJsonPath('data.is_restorable', false);
    }

    #[Test]
    public function a_read_only_member_cannot_spend_datastore_space(): void
    {
        [$customer, $owner] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        config()->set('backups.datastores.'.$machine->cluster()->firstOrFail()->slug, 'pbs-test-01');

        $viewer = $this->memberOf($customer, CustomerRole::Member);

        // Taking a backup costs datastore space and, in stop mode, an outage
        // on a machine their colleagues are using.
        $this->actingAs($viewer)
            ->postJson('/api/v1/vps/'.$machine->id.'/backups')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');

        // The owner may, on the same machine.
        $this->actingAs($owner)
            ->postJson('/api/v1/vps/'.$machine->id.'/backups')
            ->assertStatus(202);
    }

    #[Test]
    public function an_unconfigured_cluster_says_so_without_naming_the_configuration(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        config()->set('backups.datastores', []);

        $body = (string) $this->actingAs($user)
            ->postJson('/api/v1/vps/'.$machine->id.'/backups')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'backups.not_configured')
            ->getContent();

        // The customer is told it is our problem. The configuration key that
        // would fix it is an internal detail and stays in the log.
        $this->assertStringNotContainsString('backups.datastores', $body);
    }
}
