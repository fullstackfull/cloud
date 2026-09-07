<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The question this file answers is not "is a suspended machine refused" but
 * "is there any customer door left open".
 *
 * It is written as a total sweep over ServiceStatus rather than as a test per
 * status, because the failure it guards against is not a missing check today —
 * it is the status somebody adds next year. `reactivating` was added in this
 * phase, and a per-status test suite would have said nothing about it: every
 * existing test would have gone on passing while a machine mid-reactivation
 * accepted reboots.
 *
 * Every non-usable status must be refused by every customer surface that can
 * reach a hypervisor, and the refusal must leave nothing behind — no queued
 * job, no dispatch, no console permit. A 409 that still enqueues the work is
 * the same bypass with a slower fuse.
 */
final class NoCustomerPathBypassesSuspensionTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * Every status a service can hold that is not "you may use this".
     *
     * @return iterable<string, array{0: ServiceStatus}>
     */
    public static function unusableStatuses(): iterable
    {
        foreach (ServiceStatus::cases() as $status) {
            if ($status->isUsable()) {
                continue;
            }

            yield $status->value => [$status];
        }
    }

    #[Test]
    #[DataProvider('unusableStatuses')]
    public function power_is_refused_and_nothing_is_queued(ServiceStatus $status): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, status: $status);

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'bypass-'.$status->value)
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'start']);

        $this->assertGreaterThanOrEqual(
            400,
            $response->status(),
            sprintf('A service in %s accepted a power request.', $status->value),
        );

        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    #[DataProvider('unusableStatuses')]
    public function a_reinstall_is_refused_and_nothing_is_queued(ServiceStatus $status): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, status: $status, hostname: 'web-01');

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'bypass-reinstall-'.$status->value)
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-01']);

        $this->assertGreaterThanOrEqual(
            400,
            $response->status(),
            sprintf('A service in %s accepted a reinstall.', $status->value),
        );

        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNotPushed(RunProvisioningJob::class);
    }

    #[Test]
    #[DataProvider('unusableStatuses')]
    public function no_console_permit_is_issued(ServiceStatus $status): void
    {
        /*
         * The door that matters most. A console is a keyboard on the box: a
         * customer holding one does not need the platform's power API, because
         * they can reboot from inside the guest — and on a suspended machine
         * they could undo everything the suspension did.
         */
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, status: $status);

        $response = $this->actingAs($user)->getJson('/api/v1/vps/'.$machine->id.'/console');

        $this->assertGreaterThanOrEqual(
            400,
            $response->status(),
            sprintf('A service in %s was handed a console permit.', $status->value),
        );

        $this->assertNull($response->json('data.token'));
    }

    #[Test]
    public function an_active_service_is_still_accepted(): void
    {
        /*
         * The control that stops the sweep above passing because the endpoints
         * refuse everybody. Without it, deleting a route would make this file
         * greener.
         */
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'control-start-1')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'start'])
            ->assertStatus(202);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201);
    }

    #[Test]
    public function the_portal_does_not_offer_operations_it_will_refuse(): void
    {
        // A button that returns 409 is not a closed door, it is a door with a
        // sign. The list marks the machine inoperable so the portal disables
        // the controls rather than offering them.
        [$customer, $user] = $this->accountWithOwner();
        $this->machineFor($customer, status: ServiceStatus::Suspended);

        $this->actingAs($user)
            ->getJson('/api/v1/vps')
            ->assertOk()
            ->assertJsonPath('data.0.is_operable', false);
    }
}
