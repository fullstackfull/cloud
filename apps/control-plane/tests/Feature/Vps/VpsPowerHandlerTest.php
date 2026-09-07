<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Infrastructure\ComputeProviderFactory;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Provisioning\Application\Actions\CompensateFailedJob;
use Lynomia\Modules\Provisioning\Application\Actions\TransitionService;
use Lynomia\Modules\Provisioning\Application\Jobs\RunProvisioningJob;
use Lynomia\Modules\Provisioning\Domain\Contracts\HandlerRegistry;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ProvisioningJobStateMachine;
use Lynomia\Modules\Provisioning\Domain\StateMachines\ServiceStateMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Shared\Infrastructure\Logging\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Doubles\PowerRecordingComputeProvider;

/**
 * The handlers behind the power endpoints, executed through the real engine.
 *
 * The endpoint tests prove work is recorded and dispatched; these prove what
 * happens when it runs — in particular the one distinction the whole module is
 * arranged around, that `stop` and `shutdown` reach two different calls on the
 * hypervisor.
 *
 * They now also prove the application reaches them at all. These handlers used
 * to be registered by this setUp() because InfrastructureServiceProvider
 * registered only CreateVps, and the effect of testing them that way was that
 * every assertion below passed while a customer pressing "reboot" in
 * production got a job no worker could execute. The registration is gone from
 * here on purpose: everything under test resolves through the container the
 * application actually boots, so if that wiring is ever removed these tests go
 * red with it.
 */
final class VpsPowerHandlerTest extends VpsApiTestCase
{
    private PowerRecordingComputeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new PowerRecordingComputeProvider;
    }

    #[Test]
    public function shutdown_asks_the_guest_and_stop_pulls_the_plug(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->swapProvider($machine->cluster()->firstOrFail());

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'graceful-down-01')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'shutdown'])
            ->assertStatus(202);

        $this->assertEngineRanInline();

        // The guest was asked, not unplugged. Getting this the other way round
        // loses every write the guest had not flushed.
        $this->assertSame(['shutdownVm'], $this->provider->calls);

        $this->assertSame(PowerState::Stopped, $machine->fresh()?->power_state);
        $this->assertSame(ProvisioningJobStatus::Succeeded, ProvisioningJob::query()->sole()->status);
    }

    #[Test]
    public function stop_reaches_the_hard_call(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->swapProvider($machine->cluster()->firstOrFail());

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'hard-down-0001')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'stop'])
            ->assertStatus(202);

        $this->assertEngineRanInline();

        $this->assertSame(['stopVm'], $this->provider->calls);
    }

    #[Test]
    public function start_and_reboot_reach_their_own_calls(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer, powerState: PowerState::Stopped);

        $this->swapProvider($machine->cluster()->firstOrFail());

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'power-on-000001')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'start'])
            ->assertStatus(202);

        $this->assertEngineRanInline();
        $this->assertSame(['startVm'], $this->provider->calls);
        $this->assertSame(PowerState::Running, $machine->fresh()?->power_state);

        ProvisioningJob::query()->update(['status' => ProvisioningJobStatus::Succeeded]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'reboot-000001x')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'reboot'])
            ->assertStatus(202);

        $this->assertEngineRanInline();

        // rebootVm, never resetVm. The hard reset is not reachable from any
        // kind this module dispatches.
        $this->assertSame(['startVm', 'rebootVm'], $this->provider->calls);
    }

    #[Test]
    public function a_timed_out_power_call_is_escalated_and_never_repeated(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->provider->failWith = ComputeProviderException::requestFailed(
            'recording',
            'stop_vm',
            ['node' => 'pve-01'],
            indeterminate: true,
        );

        $this->swapProvider($machine->cluster()->firstOrFail());

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'timeout-stop-01')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'stop'])
            ->assertStatus(202);

        $this->assertEngineRanInline();

        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::query()->sole();

        /*
         * A timeout means the platform stopped waiting, not that the
         * hypervisor stopped working. The machine may be going down right now,
         * so the job waits for a person rather than being retried — and the
         * provider is called exactly once.
         */
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->status);
        $this->assertSame(1, $job->attempts);
        $this->assertSame(['stopVm'], $this->provider->calls);
    }

    #[Test]
    public function a_job_whose_payload_names_no_action_is_refused_rather_than_guessed_at(): void
    {
        [$customer] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->swapProvider($machine->cluster()->firstOrFail());

        $job = ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $customer->id,
            'kind' => ProvisioningJobKind::Stop,
            'status' => ProvisioningJobStatus::Queued,
            'payload' => ['virtual_machine_id' => $machine->id],
        ]);

        (new RunProvisioningJob((string) $job->getKey()))->handle(
            app(HandlerRegistry::class),
            app(CompensateFailedJob::class),
            app(TransitionService::class),
            app(ServiceStateMachine::class),
            app(ProvisioningJobStateMachine::class),
            app(SecretRedactor::class),
        );

        // The two plausible guesses differ by whether the customer loses their
        // unflushed writes, so neither is made.
        $this->assertSame([], $this->provider->calls);
        $this->assertSame('vps.unknown_power_action', $job->fresh()?->result['error']['code']);
    }

    /**
     * Once the engine has run there is a provider task handle on the job — in
     * Proxmox's case a UPID that names the node. The receipt is re-read here,
     * after the fact, because the endpoint test can only inspect a job that
     * has not reached a hypervisor yet.
     */
    #[Test]
    public function the_receipt_never_carries_the_provider_task_handle(): void
    {
        [$customer, $user] = $this->accountWithOwner();
        $machine = $this->machineFor($customer);

        $this->swapProvider($machine->cluster()->firstOrFail());

        $body = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'receipt-after-run')
            ->postJson('/api/v1/vps/'.$machine->id.'/power', ['action' => 'shutdown'])
            ->assertStatus(202)
            ->getContent() ?: '';

        $this->assertEngineRanInline();

        /** @var ProvisioningJob $job */
        $job = ProvisioningJob::query()->sole();

        $this->assertNotNull($job->remote_job_id, 'The engine did not record a provider task handle to test against.');
        $this->assertStringNotContainsString((string) $job->remote_job_id, $body);
        $this->assertStringNotContainsString($machine->node()->firstOrFail()->provider_name, $body);
    }

    private function swapProvider(ComputeCluster $cluster): void
    {
        // The factory is not a singleton, so the instance the handler
        // resolves has to be the one the recorder was swapped into.
        $factory = app(ComputeProviderFactory::class);
        $factory->swap($cluster, $this->provider);
        app()->instance(ComputeProviderFactory::class, $factory);
    }

    /**
     * The queue is deliberately not faked in this class: the point is to
     * execute the handler.
     *
     * QUEUE_CONNECTION is `sync` under phpunit, so the dispatch inside the
     * request already ran the engine. This states that assumption rather than
     * relying on it silently — if the connection ever changes, these tests
     * fail here rather than quietly asserting nothing.
     *
     * It asserts that no job is left QUEUED, not merely that a job exists: a
     * row exists whether or not a worker ever touched it, so the original
     * form of this helper would have gone on passing with the queue faked and
     * every call below asserting against an engine that never ran.
     */
    private function assertEngineRanInline(): void
    {
        $this->assertNotSame(0, ProvisioningJob::query()->count(), 'No job was recorded at all.');

        $this->assertSame(
            0,
            ProvisioningJob::query()->where('status', ProvisioningJobStatus::Queued->value)->count(),
            'A job is still queued, so the engine did not run inside the request: '
            .'these tests assert against a hypervisor call that never happened.',
        );
    }
}
