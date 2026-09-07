<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regressions found reviewing the first cut of this surface.
 *
 * Each test here failed against the behaviour that shipped in that cut; the
 * comment on each says what it was and why it mattered.
 */
final class VpsReviewRegressionTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * @return list<array{0: ServiceStatus}>
     */
    public static function nonActiveStatuses(): array
    {
        return [
            [ServiceStatus::Suspended],
            [ServiceStatus::Terminated],
            [ServiceStatus::Failed],
            [ServiceStatus::Provisioning],
        ];
    }

    /**
     * A console is root access, so it cannot be more permissive than a reboot.
     *
     * The first cut gated the console only on the hypervisor having confirmed
     * the machine, never on the service. A suspended service is one the
     * platform deliberately cut off — for non-payment, or for abuse — and a
     * terminated one is a service somebody stopped paying for; both still
     * handed out a live permit to a keyboard on the box while POST /power
     * refused with 409.
     */
    #[Test]
    #[DataProvider('nonActiveStatuses')]
    public function a_service_that_is_not_active_gets_no_console(ServiceStatus $status): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, status: $status);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.not_active');
    }

    /**
     * The carve-out the console exists for is untouched: a machine whose guest
     * is off, on a service that is still active, is exactly who needs one.
     */
    #[Test]
    public function an_active_service_with_a_stopped_guest_still_gets_a_console(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, powerState: PowerState::Stopped);

        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(201);
    }

    /**
     * needs_review is where a TIMED-OUT operation comes to rest.
     *
     * The engine puts a job there precisely because nobody knows whether the
     * provider finished it — that is the whole point of the status. The first
     * cut's in-flight guard only looked at queued and running, so the moment a
     * reinstall timed out the API would accept a second one: a rebuild queued
     * on top of a rebuild that may still be running on the hypervisor, with
     * the disk in an unknown state.
     */
    #[Test]
    public function a_timed_out_operation_blocks_a_second_reinstall(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $customer->id,
            'kind' => ProvisioningJobKind::Reinstall,
            'status' => ProvisioningJobStatus::NeedsReview,
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-on-a-rebuild')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-01'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.operation_needs_review');

        // Refused, not recorded: a second job row here is a second rebuild
        // waiting for a worker.
        $this->assertSame(1, ProvisioningJob::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_timed_out_operation_blocks_a_power_change(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $customer->id,
            'kind' => ProvisioningJobKind::Stop,
            'status' => ProvisioningJobStatus::NeedsReview,
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'power-over-a-timeout')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'start'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.operation_needs_review');

        $this->assertSame(1, ProvisioningJob::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * A settled job that did NOT time out is not a reason to refuse anything.
     */
    #[Test]
    public function a_finished_operation_does_not_block_the_next_one(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        foreach ([ProvisioningJobStatus::Succeeded, ProvisioningJobStatus::Failed, ProvisioningJobStatus::Cancelled] as $status) {
            ProvisioningJob::factory()->create([
                'service_id' => $machine->service_id,
                'customer_id' => $customer->id,
                'kind' => ProvisioningJobKind::Stop,
                'status' => $status,
            ]);
        }

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'after-a-clean-stop')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'start'])
            ->assertStatus(202);
    }

    /**
     * An ssh_keys entry is one key on one line.
     *
     * CloudInitConfig joins the list with "\n" to build the authorized_keys
     * block, so an entry carrying its own newline is several keys wearing one
     * array slot — which also makes the `max:20` bound decorative. Rejected at
     * the edge rather than discovered in a guest.
     */
    #[Test]
    public function an_ssh_key_carrying_its_own_newline_is_refused(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'smuggled-second-key')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', [
                'confirm_hostname' => 'web-01',
                'ssh_keys' => ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI me\nssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI attacker"],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['ssh_keys.0']]]]);

        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_well_formed_ssh_key_is_still_accepted(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        $key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIB1mDoIsF7pKQZ3wYw6qkQyPjEPRfM7uYNaLuP1E4Wxy someone@example.com';

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'a-real-key-please')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', [
                'confirm_hostname' => 'web-01',
                'ssh_keys' => [$key],
            ])
            ->assertStatus(202);

        $this->assertSame([$key], ProvisioningJob::query()->sole()->payload['ssh_keys']);
    }

    /**
     * The two tighter limiters on this module have to be separate buckets.
     *
     * A numeric `throttle:N,1` with no prefix keys on the caller alone, so
     * every such route in the application shares one counter. In the first cut
     * ten console GETs — a shape a browser produces by itself, refreshing a
     * page — spent the reinstall allowance, and a customer whose machine had
     * just been compromised got a 429 on the endpoint that rebuilds it.
     */
    #[Test]
    public function opening_consoles_does_not_spend_the_reinstall_allowance(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->getJson('/api/v1/vps/'.$machine->id.'/console')->assertStatus(201);
        }

        // The console's own ceiling still bites...
        $this->actingAs($user)
            ->getJson('/api/v1/vps/'.$machine->id.'/console')
            ->assertStatus(429);

        // ...and the emergency exit is still open.
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-after-consoles')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-01'])
            ->assertStatus(202);
    }

    /**
     * The scope follows the ACTING customer, not "any account this login
     * belongs to".
     *
     * One user, two accounts, and a header naming the first: the second
     * account's machine must be as invisible as a stranger's. Every other
     * cross-tenant test on this surface uses two separate logins, which cannot
     * tell a query scoped through the acting customer from one scoped through
     * the user's memberships.
     */
    #[Test]
    public function a_user_who_belongs_to_two_accounts_sees_only_the_one_they_are_acting_for(): void
    {
        [$first, $user] = $this->accountWithOwner();
        [$second] = $this->accountWithOwner();
        $this->memberOf($second, CustomerRole::Owner, $user);

        $mine = $this->machineFor($first, hostname: 'first-web');
        $other = $this->machineFor($second, hostname: 'second-web');

        $list = $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $first->id)
            ->getJson('/api/v1/vps')
            ->assertOk();

        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->assertStringNotContainsString('second-web', $list->getContent() ?: '');

        foreach (['', '/console'] as $suffix) {
            $this->actingAs($user)
                ->withHeader('X-Lynomia-Customer', $first->id)
                ->getJson('/api/v1/vps/'.$other->id.$suffix)
                ->assertNotFound()
                ->assertJsonPath('error.code', 'resource.not_found');
        }

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $first->id)
            ->withHeader('Idempotency-Key', 'other-account-stop')
            ->postJson('/api/v1/vps/'.$other->id.'/power', ['action' => 'stop'])
            ->assertNotFound();

        $this->actingAs($user)
            ->withHeader('X-Lynomia-Customer', $first->id)
            ->withHeader('Idempotency-Key', 'other-account-wipe')
            ->postJson('/api/v1/vps/'.$other->id.'/reinstall', ['confirm_hostname' => 'second-web'])
            ->assertNotFound();

        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNothingPushed();
    }
}
