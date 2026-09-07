<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/vps/{vm}.
 */
final class ShowVirtualMachineEndpointTest extends VpsApiTestCase
{
    #[Test]
    public function a_machine_comes_back_with_what_its_owner_needs(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'db-primary');

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id)
            ->assertOk()
            ->assertJsonPath('data.id', $machine->id)
            ->assertJsonPath('data.hostname', 'db-primary')
            ->assertJsonPath('data.power_state', PowerState::Running->value)
            ->assertJsonPath('data.service_status', ServiceStatus::Active->value)
            ->assertJsonPath('data.is_operable', true)
            ->assertJsonPath('data.resources.memory_mib', 4096);
    }

    #[Test]
    public function another_customers_machine_is_a_404_and_not_a_403(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = $this->machineFor($theirs, hostname: 'not-yours');

        /*
         * 404 rather than 403. A 403 would confirm that the id names a real
         * machine, and on ULIDs that difference is an enumeration oracle over
         * the whole platform's fleet.
         */
        $response = $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$foreign->id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        $this->assertStringNotContainsString('not-yours', $response->getContent() ?: '');
    }

    #[Test]
    public function a_machine_belonging_to_a_service_of_another_account_is_not_reachable_by_service_id_either(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = $this->machineFor($theirs);

        // The scope joins customers → services → machines, so neither the
        // machine id nor the service id is a way in.
        $this->actingAs($user)->getJson('/api/v1/vps/'.$foreign->service_id)->assertNotFound();
    }

    #[Test]
    public function a_machine_the_hypervisor_has_not_confirmed_is_readable_but_marked_inoperable(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->unprovisionedMachineFor($customer);

        // Readable on purpose: the row exists before the create call returns,
        // and a customer whose order is mid-build should see the machine
        // rather than a 404 that looks like it was never ordered.
        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id)
            ->assertOk()
            ->assertJsonPath('data.id', $machine->id)
            ->assertJsonPath('data.is_operable', false);
    }

    #[Test]
    public function nothing_internal_is_serialised(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $response = $this->actingAs($user)->getJson('/api/v1/vps/'.$machine->id)->assertOk();
        $row = $response->json('data');

        foreach (['provider_id', 'node_id', 'cluster_id', 'template_id', 'has_drift', 'drift_details', 'last_reconciled_at'] as $internal) {
            $this->assertArrayNotHasKey($internal, $row, sprintf('"%s" must not reach a customer.', $internal));
        }

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString((string) $machine->provider_id, $body);
        $this->assertStringNotContainsString((string) $machine->node_id, $body);
        $this->assertStringNotContainsString((string) $machine->cluster_id, $body);
        $this->assertStringNotContainsString($this->node()->provider_name, $body);
    }
}
