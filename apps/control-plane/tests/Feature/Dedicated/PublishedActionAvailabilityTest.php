<?php

declare(strict_types=1);

namespace Tests\Feature\Dedicated;

use Lynomia\Modules\Dedicated\Domain\Enums\DedicatedServerStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the server resource says a customer may press.
 *
 * Mirrors {@see \Tests\Feature\Vps\PublishedActionAvailabilityTest} for
 * physical machines, with the one difference the dedicated guard has: a
 * power request is refused only while a REINSTALL is live, because it is the
 * customer's recovery tool, whereas a reinstall is refused while any job is.
 */
final class PublishedActionAvailabilityTest extends DedicatedApiTestCase
{
    #[Test]
    public function a_delivered_machine_with_nothing_against_it_may_be_powered_and_rebuilt(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        $this->actingAs($user)
            ->getJson("/api/v1/dedicated/{$server->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.power', true)
            ->assertJsonPath('data.actions.reinstall', true)
            ->assertJsonPath('data.actions.blocked_reason', null);
    }

    #[Test]
    public function a_live_reinstall_blocks_power_and_rebuild_alike(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::ReinstallDedicated,
            'customer_id' => $customer->getKey(),
            'service_id' => $server->service_id,
            'status' => ProvisioningJobStatus::Running,
            'payload' => ['dedicated_server_id' => (string) $server->getKey()],
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/dedicated')
            ->assertJsonPath('data.0.actions.power', false)
            ->assertJsonPath('data.0.actions.reinstall', false)
            ->assertJsonPath('data.0.actions.blocked_reason', 'reinstall_in_flight');
    }

    #[Test]
    public function other_live_work_blocks_a_rebuild_but_leaves_the_power_controls_alone(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::ProvisionDedicated,
            'customer_id' => $customer->getKey(),
            'service_id' => $server->service_id,
            'status' => ProvisioningJobStatus::Queued,
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/dedicated/{$server->id}")
            ->assertJsonPath('data.actions.power', true)
            ->assertJsonPath('data.actions.reinstall', false)
            ->assertJsonPath('data.actions.blocked_reason', 'operation_in_flight');
    }

    #[Test]
    public function a_machine_under_maintenance_may_not_be_touched(): void
    {
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer, ['status' => DedicatedServerStatus::Maintenance]);

        $this->actingAs($user)
            ->getJson("/api/v1/dedicated/{$server->id}")
            ->assertJsonPath('data.actions.power', false)
            ->assertJsonPath('data.actions.reinstall', false)
            ->assertJsonPath('data.actions.blocked_reason', 'server_not_in_service');
    }

    #[Test]
    public function a_stranded_job_does_not_block_a_physical_machine(): void
    {
        // The dedicated guard reads queued and running only; a job at
        // needs_review is an operator's problem and does not take the
        // customer's controls away. The resource says what the guard does,
        // not what the VPS guard does.
        [$customer, $user] = $this->accountWith();
        $server = $this->serverFor($customer);

        ProvisioningJob::factory()->create([
            'kind' => ProvisioningJobKind::ReinstallDedicated,
            'customer_id' => $customer->getKey(),
            'service_id' => $server->service_id,
            'status' => ProvisioningJobStatus::NeedsReview,
            'payload' => ['dedicated_server_id' => (string) $server->getKey()],
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/dedicated/{$server->id}")
            ->assertJsonPath('data.actions.reinstall', true)
            ->assertJsonPath('data.actions.blocked_reason', null);
    }
}
