<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Ipam\Domain\Enums\IpVersion;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Ipam\Infrastructure\Models\Subnet;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /api/v1/vps.
 */
final class ListVirtualMachinesEndpointTest extends VpsApiTestCase
{
    #[Test]
    public function it_lists_only_the_acting_customers_machines(): void
    {
        [$mine, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $ours = $this->machineFor($mine, hostname: 'web-01');
        $foreign = $this->machineFor($theirs, hostname: 'their-web-01');

        $response = $this->actingAs($user)->getJson('/api/v1/vps')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ours->id)
            ->assertJsonPath('data.0.hostname', 'web-01')
            ->assertJsonPath('data.0.resources.vcpu', 2)
            ->assertJsonPath('data.0.power_state', PowerState::Running->value)
            ->assertJsonPath('data.0.service_status', 'active')
            ->assertJsonPath('meta.total', 1);

        // Not merely absent from the page — absent from the response entirely.
        $this->assertStringNotContainsString($foreign->id, $response->getContent() ?: '');
        $this->assertStringNotContainsString('their-web-01', $response->getContent() ?: '');
    }

    #[Test]
    public function the_page_size_is_bounded_however_much_is_asked_for(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        // 101 machines against a ceiling of 100, so "everything" and "the
        // maximum page" are different numbers and the assertion can tell them
        // apart.
        for ($i = 0; $i < 101; $i++) {
            $this->machineFor($customer);
        }

        $this->actingAs($user)
            ->getJson('/api/v1/vps?per_page=100000')
            ->assertOk()
            ->assertJsonCount(100, 'data')
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.max_per_page', 100)
            ->assertJsonPath('meta.total', 101);
    }

    #[Test]
    public function a_nonsense_page_size_falls_back_rather_than_walking_the_fleet_one_row_at_a_time(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $this->machineFor($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/vps?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 25);
    }

    #[Test]
    public function an_unparseable_filter_is_a_422_naming_the_field(): void
    {
        [, $user] = $this->accountWithOwner();

        $this->actingAs($user)
            ->getJson('/api/v1/vps?power_state=melted')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['power_state']]]]);
    }

    #[Test]
    public function nothing_internal_is_serialised(): void
    {
        [$customer, $user] = $this->accountWithOwner();

        $machine = $this->machineFor($customer);
        $machine->forceFill([
            'has_drift' => true,
            'drift_details' => ['vcpu' => ['expected' => 2, 'observed' => 4]],
            'last_reconciled_at' => now(),
        ])->save();

        $row = $this->actingAs($user)->getJson('/api/v1/vps')->assertOk()->json('data.0');

        foreach (['provider_id', 'node_id', 'cluster_id', 'template_id', 'has_drift', 'drift_details', 'last_reconciled_at'] as $internal) {
            $this->assertArrayNotHasKey($internal, $row, sprintf('"%s" must not reach a customer.', $internal));
        }

        // The hypervisor's own id for the machine, by value rather than by key:
        // a resource that renamed the field would still be leaking it.
        $body = $this->actingAs($user)->getJson('/api/v1/vps')->getContent() ?: '';
        $this->assertStringNotContainsString((string) $machine->provider_id, $body);
        $this->assertStringNotContainsString((string) $machine->node_id, $body);
        $this->assertStringNotContainsString((string) $machine->cluster_id, $body);
    }

    #[Test]
    public function a_live_address_is_shown_and_a_released_one_is_not(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $subnet = Subnet::factory()->create();

        $live = IpAddress::factory()->create([
            'subnet_id' => $subnet->id,
            'address' => '192.0.2.10',
            'ip_version' => IpVersion::V4,
        ]);

        $released = IpAddress::factory()->create([
            'subnet_id' => $subnet->id,
            'address' => '192.0.2.11',
            'ip_version' => IpVersion::V4,
        ]);

        IpAssignment::factory()->create([
            'ip_address_id' => $live->id,
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'assignable_type' => VirtualMachine::class,
            'assignable_id' => $machine->id,
            'is_primary' => true,
            'assigned_at' => now(),
            'released_at' => null,
        ]);

        // Assignments are append-only history. A released row is somebody
        // else's address now, and showing it would tell a customer to connect
        // to a machine that is not theirs.
        IpAssignment::factory()->create([
            'ip_address_id' => $released->id,
            'customer_id' => $customer->id,
            'service_id' => $machine->service_id,
            'assignable_type' => VirtualMachine::class,
            'assignable_id' => $machine->id,
            'is_primary' => false,
            'assigned_at' => now()->subDay(),
            'released_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/vps')->assertOk();

        $response->assertJsonCount(1, 'data.0.addresses')
            ->assertJsonPath('data.0.addresses.0.address', '192.0.2.10')
            ->assertJsonPath('data.0.addresses.0.is_primary', true)
            // Named as the IPAM endpoints name it, and a number as they send
            // it. assertJsonPath compares strictly, so this fails if the
            // int-backed enum is ever stringified on the way out.
            ->assertJsonPath('data.0.addresses.0.ip_version', 4);

        $this->assertStringNotContainsString('192.0.2.11', $response->getContent() ?: '');
    }

    #[Test]
    public function a_member_may_read_the_fleet(): void
    {
        [$customer, $user] = $this->accountWithOwner(CustomerRole::Member);
        $this->machineFor($customer);

        $this->actingAs($user)->getJson('/api/v1/vps')->assertOk()->assertJsonCount(1, 'data');
    }
}
