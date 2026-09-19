<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the machine resource says a customer may press, and that the endpoint
 * agrees.
 *
 * The screen used to enable Reinstall on a machine whose last rebuild had
 * timed out, and the API then answered 409 — a door with a sign. The resource
 * now publishes `actions` from the same facts the operation guard refuses on,
 * so the button and the refusal cannot disagree. Every case below asserts
 * both halves.
 */
final class PublishedActionAvailabilityTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function a_machine_with_nothing_against_it_may_be_powered_and_rebuilt(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id)
            ->assertOk()
            ->assertJsonPath('data.actions.power', true)
            ->assertJsonPath('data.actions.reinstall', true)
            ->assertJsonPath('data.actions.blocked_reason', null);
    }

    #[Test]
    public function a_rebuild_waiting_for_a_person_blocks_both_and_says_so(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::ReinstallVps,
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'status' => ProvisioningJobStatus::NeedsReview,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/vps')
            ->assertOk()
            ->assertJsonPath('data.0.is_operable', true)
            ->assertJsonPath('data.0.actions.power', false)
            ->assertJsonPath('data.0.actions.reinstall', false)
            ->assertJsonPath('data.0.actions.blocked_reason', 'operation_needs_review');

        // And the endpoint refuses exactly what the resource said it would.
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-over-a-stranded-one')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-01'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.operation_needs_review');

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'reboot-over-a-stranded-one')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'reboot'])
            ->assertStatus(409);
    }

    #[Test]
    public function live_work_blocks_both_and_names_itself_as_the_reason(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::Restart,
            'customer_id' => $customer->getKey(),
            'service_id' => $machine->service_id,
            'status' => ProvisioningJobStatus::Running,
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id)
            ->assertJsonPath('data.actions.reinstall', false)
            ->assertJsonPath('data.actions.blocked_reason', 'operation_in_flight');
    }

    #[Test]
    public function live_work_wins_over_stranded_work_as_the_reason(): void
    {
        // The guard's own precedence: "wait for this to finish" is the answer
        // a customer can act on, so it is the one the screen shows.
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        foreach ([ProvisioningJobStatus::NeedsReview, ProvisioningJobStatus::Queued] as $status) {
            ProvisioningJob::factory()->create([
                'kind' => ProvisioningJobKind::Restart,
                'customer_id' => $customer->getKey(),
                'service_id' => $machine->service_id,
                'status' => $status,
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id)
            ->assertJsonPath('data.actions.blocked_reason', 'operation_in_flight');
    }

    #[Test]
    public function a_suspended_service_blocks_both_before_any_job_is_consulted(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, ServiceStatus::Suspended);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id)
            ->assertJsonPath('data.is_operable', false)
            ->assertJsonPath('data.actions.power', false)
            ->assertJsonPath('data.actions.reinstall', false)
            ->assertJsonPath('data.actions.blocked_reason', 'service_not_active');
    }

    #[Test]
    public function a_machine_the_hypervisor_never_confirmed_is_blocked_as_not_provisioned(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->unprovisionedMachineFor($customer);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id)
            ->assertJsonPath('data.actions.reinstall', false)
            ->assertJsonPath('data.actions.blocked_reason', 'not_provisioned');
    }

    #[Test]
    public function one_machines_stranded_job_does_not_block_its_neighbour(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $stranded = $this->machineFor($customer, hostname: 'stranded');
        $this->machineFor($customer, hostname: 'fine');

        ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::ReinstallVps,
            'customer_id' => $customer->getKey(),
            'service_id' => $stranded->service_id,
            'status' => ProvisioningJobStatus::NeedsReview,
        ]);

        $rows = collect($this->actingAs($user)->getJson('/api/v1/vps')->assertOk()->json('data'))->keyBy('hostname');

        $this->assertFalse($rows['stranded']['actions']['reinstall']);
        $this->assertTrue($rows['fine']['actions']['reinstall']);
        $this->assertNull($rows['fine']['actions']['blocked_reason']);
    }
}
