<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Illuminate\Support\Facades\Queue;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /api/v1/vps/{vm}/power.
 *
 * The queue is faked throughout: what this endpoint promises is that the work
 * is *recorded and dispatched*, not that a hypervisor has obeyed. Letting the
 * sync queue run the engine inside the request would test the engine, which
 * has its own suite, and would hide whether the controller dispatched at all.
 */
final class VpsPowerEndpointTest extends VpsApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function actions(): array
    {
        return [
            ['start', ProvisioningJobKind::Start->value],
            ['stop', ProvisioningJobKind::Stop->value],
            ['shutdown', ProvisioningJobKind::Stop->value],
            ['reboot', ProvisioningJobKind::Restart->value],
        ];
    }

    #[Test]
    #[DataProvider('actions')]
    public function each_action_is_accepted_and_queued(string $action, string $expectedKind): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'power-'.$action.'-001')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => $action])
            ->assertStatus(202)
            ->assertJsonPath('data.kind', $expectedKind)
            ->assertJsonPath('data.action', $action)
            ->assertJsonPath('data.status', ProvisioningJobStatus::Queued->value)
            ->assertJsonPath('data.service_id', $machine->service_id);

        Queue::assertPushed(RunProvisioningJob::class, 1);
    }

    #[Test]
    public function stop_and_shutdown_are_not_the_same_operation(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'graceful-please-1')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'shutdown'])
            ->assertStatus(202);

        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::query()->where('service_id', $machine->service_id)->sole();

        /*
         * The engine has one kind for both, so the payload is the only place
         * the difference can live. If `power_action` said "stop" here, the
         * handler would cut the power on a customer who asked the guest
         * politely — and every write the guest had not flushed would be gone.
         */
        $this->assertSame('shutdown', $job->payload['power_action']);
        $this->assertTrue($job->payload['graceful']);
    }

    #[Test]
    public function another_customers_machine_is_a_404_and_not_a_403(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = $this->machineFor($theirs);

        /*
         * 404, deliberately. A 403 would confirm the id names a real machine,
         * which on ULIDs is the difference between "you may not reboot this"
         * and "this exists" — and the second is an enumeration oracle over
         * every machine on the platform.
         */
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'cross-tenant-01')
            ->postJson('/api/v1/vps/'.$foreign->id.'/power', ['action' => 'stop'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'resource.not_found');

        // And nothing was queued for the machine the caller does not own.
        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_unknown_id_answers_exactly_as_another_customers_id_does(): void
    {
        [, $user] = $this->accountWithOwner();
        [$theirs] = $this->accountWithOwner();

        $foreign = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'oracle-probe-01')
            ->postJson('/api/v1/vps/'.$this->machineFor($theirs)->id.'/power', ['action' => 'stop']);

        $invented = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'oracle-probe-02')
            ->postJson('/api/v1/vps/01JZZZZZZZZZZZZZZZZZZZZZZZ/power', ['action' => 'stop']);

        $this->assertSame($foreign->status(), $invented->status());
        $this->assertSame($foreign->json('error.code'), $invented->json('error.code'));
        $this->assertSame($foreign->json('error.message'), $invented->json('error.message'));
    }

    #[Test]
    public function a_machine_whose_service_is_not_active_is_refused_rather_than_queued(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, status: ServiceStatus::Suspended);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'suspended-start-1')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'start'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.not_active');

        // Refused, not deferred: a queued start against a suspended service
        // would undo the suspension the platform imposed, minutes later, with
        // nobody watching.
        $this->assertSame(0, ProvisioningJob::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_machine_the_hypervisor_has_never_confirmed_is_refused(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->unprovisionedMachineFor($customer);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'unbuilt-reboot-1')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'reboot'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.not_provisioned');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_second_operation_is_refused_while_one_is_still_running(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $customer->id,
            'kind' => ProvisioningJobKind::ReinstallVps,
            'status' => ProvisioningJobStatus::Running,
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'racing-the-rebuild')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'stop'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'vps.operation_in_flight');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_same_idempotency_key_returns_the_same_operation_and_queues_once(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'reboot-once-please')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'reboot'])
            ->assertStatus(202);

        $second = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'reboot-once-please')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'reboot'])
            ->assertStatus(202);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ProvisioningJob::query()->count());

        // The replay is answered before the in-flight guard runs. A client
        // retrying a dropped response must get its own job back, not a 409
        // for the job it is retrying.
        Queue::assertPushed(RunProvisioningJob::class, 1);
    }

    #[Test]
    public function one_customers_idempotency_key_cannot_claim_anothers_operation(): void
    {
        [$mine, $me] = $this->accountWithOwner();
        [$theirs, $them] = $this->accountWithOwner();

        $myMachine = $this->machineFor($mine);
        $theirMachine = $this->machineFor($theirs);

        $first = $this->actingAs($me)
            ->withHeader('Idempotency-Key', 'restart-my-server')
            ->postJson('/api/v1/vps/'.$myMachine->id.'/power', ['action' => 'reboot'])
            ->assertStatus(202);

        // The engine's key column is unique platform-wide. Without the machine
        // id in the derived key, this caller would be handed a job for a
        // machine they do not own — and their own request would never run.
        $second = $this->actingAs($them)
            ->withHeader('Idempotency-Key', 'restart-my-server')
            ->postJson('/api/v1/vps/'.$theirMachine->id.'/power', ['action' => 'reboot'])
            ->assertStatus(202);

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($theirMachine->service_id, $second->json('data.service_id'));
    }

    #[Test]
    public function the_same_key_used_for_a_different_action_is_a_different_operation(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $stop = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'one-key-two-intents')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'stop'])
            ->assertStatus(202);

        // The stop is settled so the in-flight guard does not fire; what is
        // under test is that reusing a key for a different verb is a different
        // intent and gets its own job.
        ProvisioningJob::query()->update(['status' => ProvisioningJobStatus::Succeeded]);

        $start = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'one-key-two-intents')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'start'])
            ->assertStatus(202);

        $this->assertNotSame($stop->json('data.id'), $start->json('data.id'));
    }

    #[Test]
    public function an_unknown_verb_is_a_422_naming_the_field(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'not-a-real-verb-1')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'obliterate'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['action']]]]);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_missing_idempotency_key_is_a_422_naming_the_header(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->actingAs($user)
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'stop'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'request.idempotency_key_rejected')
            ->assertJsonPath('error.details.header', 'Idempotency-Key');
    }

    #[Test]
    public function a_key_in_the_body_cannot_stand_in_for_the_header(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        // Two sources for one key is two answers to "is this the same
        // request?", so the body's copy is discarded rather than merged.
        $this->actingAs($user)
            ->postJson('/api/v1/vps/'.$machine->id.'/power', [
                'action' => 'stop',
                'idempotency_key' => 'smuggled-in-the-body',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'request.idempotency_key_rejected');
    }

    #[Test]
    public function a_member_may_not_touch_the_power(): void
    {
        [$customer, $viewer] = $this->accountWithOwner(CustomerRole::Member);
        $machine = $this->machineFor($customer);

        $this->actingAs($viewer)
            ->withHeader('Idempotency-Key', 'member-tries-stop')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'stop'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'auth.forbidden');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_receipt_carries_nothing_from_the_engines_internals(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $row = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'receipt-contents-1')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'start'])
            ->assertStatus(202)
            ->json('data');

        foreach (['payload', 'result', 'remote_job_id', 'last_error', 'failure_class', 'attempts', 'max_attempts', 'timeout_seconds', 'idempotency_key', 'provider'] as $internal) {
            $this->assertArrayNotHasKey($internal, $row, sprintf('"%s" must not reach a customer.', $internal));
        }
    }
}
