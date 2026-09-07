<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Compute\Domain\Enums\OsFamily;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\VmTemplate;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /api/v1/vps/{vm}/reinstall.
 *
 * The destructive endpoint, so most of what is asserted here is what it
 * REFUSES to do.
 */
final class VpsReinstallEndpointTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function naming_the_machine_confirms_the_reinstall(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-web-01-a')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-01'])
            ->assertStatus(202)
            ->assertJsonPath('data.kind', ProvisioningJobKind::Reinstall->value)
            ->assertJsonPath('data.status', ProvisioningJobStatus::Queued->value);

        Queue::assertPushed(RunProvisioningJob::class, 1);

        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::query()->sole();

        /*
         * One attempt, against the engine's default of three. A reinstall that
         * failed halfway has already destroyed the disk; a second automatic
         * pass destroys whatever the first one managed to lay down.
         */
        $this->assertSame(1, $job->max_attempts);
    }

    #[Test]
    public function a_confirmation_naming_a_different_machine_is_refused(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');
        $other = $this->machineFor($customer, hostname: 'web-02');

        // Copy-pasting the wrong hostname is the exact mistake this field
        // exists to catch, and it is the one that wipes the wrong server.
        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'wrong-machine-name')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-02'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'vps.reinstall_confirmation_mismatch');

        // The correct hostname is never echoed back: doing so would turn the
        // confirmation into a two-step handshake any client could automate.
        $this->assertStringNotContainsString('web-01', $response->getContent() ?: '');

        $this->assertSame(0, ProvisioningJob::query()->count());
        $this->assertNotNull($other->id);
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_boolean_confirmation_is_not_a_confirmation(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        // `"confirm": true` is what a generated client sets in its
        // constructor. It must buy nothing.
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'boolean-confirm-01')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['confirm_hostname']]]]);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function another_customers_machine_is_a_404_and_not_a_403(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = $this->machineFor($theirs, hostname: 'their-db');

        /*
         * 404 rather than 403, and it matters more here than anywhere else on
         * the surface: a 403 would confirm that a machine with this id exists
         * and — combined with the hostname the attacker just guessed — tell
         * them their guess was right.
         */
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'wipe-their-server')
            ->postJson('/api/v1/vps/'.$foreign->id.'/reinstall', ['confirm_hostname' => 'their-db'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_replayed_key_does_not_reinstall_twice(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-once-only')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-01'])
            ->assertStatus(202);

        // A client retrying a dropped response must not wipe the disk again.
        $second = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-once-only')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-01'])
            ->assertStatus(202);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ProvisioningJob::query()->count());
        Queue::assertPushed(RunProvisioningJob::class, 1);
    }

    #[Test]
    public function a_suspended_service_cannot_be_reinstalled(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, status: ServiceStatus::Suspended, hostname: 'web-01');

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'suspended-rebuild')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-01'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.not_active');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_reinstall_is_refused_while_another_operation_is_in_flight(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $customer->id,
            'kind' => ProvisioningJobKind::Stop,
            'status' => ProvisioningJobStatus::Queued,
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'rebuild-mid-stop')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-01'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.operation_in_flight');
    }

    #[Test]
    public function a_template_staged_on_another_cluster_is_not_installable_here(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        // A real, active, installable template — but staged on hardware this
        // machine does not run on. An id in a request body is a request, not a
        // fact.
        $elsewhere = VmTemplate::factory()->create([
            'cluster_id' => $this->otherCluster(),
            'is_active' => true,
            'provider_reference' => '9001',
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'foreign-template-1')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', [
                'confirm_hostname' => 'web-01',
                'template_id' => $elsewhere->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_fleet_wide_template_is_accepted_and_recorded_on_the_job(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, hostname: 'web-01');

        $template = VmTemplate::factory()->create([
            'cluster_id' => null,
            'is_active' => true,
            'provider_reference' => '9100',
            'os_family' => OsFamily::Ubuntu,
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'ubuntu-please-01')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', [
                'confirm_hostname' => 'web-01',
                'template_id' => $template->id,
            ])
            ->assertStatus(202);

        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::query()->sole();

        $this->assertSame($template->id, $job->payload['template_id']);
        $this->assertSame(OsFamily::Ubuntu->value, $job->payload['os_family']);
    }

    #[Test]
    public function a_member_may_not_reinstall(): void
    {
        [$customer, $viewer] = $this->accountWithOwner(CustomerRole::Member);
        $machine = $this->machineFor($customer, hostname: 'web-01');

        $this->actingAs($viewer)
            ->withHeader('Idempotency-Key', 'member-tries-wipe')
            ->postJson('/api/v1/vps/'.$machine->id.'/reinstall', ['confirm_hostname' => 'web-01'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');

        $this->assertSame(0, ProvisioningJob::query()->count());
    }

    private function otherCluster(): string
    {
        return (string) ComputeCluster::factory()->create()->id;
    }
}
