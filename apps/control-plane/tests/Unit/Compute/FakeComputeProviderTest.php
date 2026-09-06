<?php

declare(strict_types=1);

namespace Tests\Unit\Compute;

use Lynomia\Modules\Compute\Domain\DTOs\CloudInitConfig;
use Lynomia\Modules\Compute\Domain\DTOs\CreateVmRequest;
use Lynomia\Modules\Compute\Domain\DTOs\ResizeVmRequest;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Domain\Enums\RemoteTaskStatus;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Domain\Exceptions\ComputeProviderException;
use Lynomia\Modules\Compute\Domain\Exceptions\FakeProviderInProductionException;
use Lynomia\Modules\Compute\Domain\Services\FakeComputeProviderGuard;
use Lynomia\Modules\Compute\Infrastructure\Providers\FakeComputeProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The fake is what every other compute test runs against, so its own
 * guarantees — determinism, a real UPID, a task that is genuinely still
 * running — have to be established here rather than assumed everywhere else.
 */
final class FakeComputeProviderTest extends TestCase
{
    private FakeComputeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeComputeProvider;
    }

    #[Test]
    public function the_fake_provider_refuses_to_be_constructed_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->expectException(FakeProviderInProductionException::class);

        new FakeComputeProvider;
    }

    #[Test]
    public function the_refusal_carries_a_stable_error_code_and_a_server_error_status(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            new FakeComputeProvider;
            $this->fail('The fake hypervisor was constructed in production.');
        } catch (FakeProviderInProductionException $e) {
            $this->assertSame('compute.fake_provider_in_production', $e->errorCode());
            $this->assertSame(500, $e->httpStatus());
            $this->assertSame('fake', $e->context()['provider']);
        }
    }

    #[Test]
    public function the_guard_permits_every_other_environment(): void
    {
        foreach (['local', 'testing', 'staging'] as $environment) {
            $this->app->detectEnvironment(fn (): string => $environment);

            FakeComputeProviderGuard::assertNotProduction('fake');
        }

        $this->addToAssertionCount(3);
    }

    #[Test]
    public function creating_a_machine_returns_a_upid_as_the_operations_remote_job_id(): void
    {
        $operation = $this->provider->createVirtualMachine($this->request());

        $this->assertStringStartsWith('UPID:pve-01:', $operation->taskId);
        $this->assertSame('101', $operation->providerId);
        $this->assertSame('create_vm', $operation->operation);

        // The id is a UPID a real cluster would accept: eight colon-separated
        // fields with the start time in hex where Proxmox puts it.
        $this->assertGreaterThanOrEqual(8, count(explode(':', $operation->taskId)));
    }

    #[Test]
    public function a_hostname_carrying_the_failure_marker_is_refused_outright(): void
    {
        try {
            $this->provider->createVirtualMachine($this->request(
                hostname: FakeComputeProvider::failingHostname('web-01'),
            ));

            $this->fail('The marker did not produce a failure.');
        } catch (ComputeProviderException $e) {
            $this->assertSame('compute.provider_request_failed', $e->errorCode());
            $this->assertSame(502, $e->httpStatus());
            $this->assertSame('create_vm', $e->context()['operation']);
        }

        // Nothing was recorded, exactly as a cluster that refused would leave
        // nothing behind.
        $this->assertNull($this->provider->getVm('pve-01', '101'));
    }

    #[Test]
    public function a_hostname_carrying_the_timeout_marker_fails_with_an_unknown_outcome(): void
    {
        /*
         * The path provisioning must get right and cannot exercise against a
         * real cluster: the call did not come back, so whether a machine
         * exists is unknown. Retrying builds a second one; releasing the node
         * capacity gives it to somebody else while the first is still running
         * on it. The fake makes both mistakes reachable in a test.
         */
        try {
            $this->provider->createVirtualMachine($this->request(
                hostname: FakeComputeProvider::failingHostname('web-01', FakeComputeProvider::TIMEOUT_MARKER),
            ));

            $this->fail('A timed-out create reported success.');
        } catch (ComputeProviderException $e) {
            $this->assertTrue($e->isIndeterminate());
            $this->assertTrue($e->context()['indeterminate']);
        }
    }

    #[Test]
    public function an_outright_refusal_is_not_an_unknown_outcome(): void
    {
        try {
            $this->provider->createVirtualMachine($this->request(
                hostname: FakeComputeProvider::failingHostname('web-01'),
            ));

            $this->fail('A refused create reported success.');
        } catch (ComputeProviderException $e) {
            // The cluster said no. Nothing was built, so the caller is free to
            // give the node its capacity back.
            $this->assertFalse($e->isIndeterminate());
        }
    }

    #[Test]
    public function a_hostname_carrying_the_task_marker_is_accepted_and_then_fails(): void
    {
        $operation = $this->provider->createVirtualMachine($this->request(
            hostname: FakeComputeProvider::failingHostname('web-01', FakeComputeProvider::TASK_FAILURE_MARKER),
        ));

        // Accepted: this is the shape of failure that matters most, because
        // the platform has already told the customer their server is coming.
        $this->assertSame(RemoteTaskStatus::Running, $operation->status);

        $task = $this->provider->getTask('pve-01', $operation->taskId);

        $this->assertSame(RemoteTaskStatus::Failed, $task->status);
        $this->assertTrue($task->isFinished());
        $this->assertFalse($task->isSuccessful());
        $this->assertNotSame('OK', $task->exitStatus);
    }

    #[Test]
    public function a_configured_delay_produces_a_task_that_is_still_running(): void
    {
        config()->set('compute.fake.task_delay_seconds', 600);

        $slow = new FakeComputeProvider;
        $operation = $slow->createVirtualMachine($this->request());

        $task = $slow->getTask('pve-01', $operation->taskId);

        // Still running is not a failure, and a caller that treats it as one
        // builds a second machine for the same order.
        $this->assertSame(RemoteTaskStatus::Running, $task->status);
        $this->assertFalse($task->isFinished());
        $this->assertNull($task->exitStatus);

        // The same UPID, read by a provider with no delay configured, is
        // finished — the outcome lives in the id, not in the object.
        config()->set('compute.fake.task_delay_seconds', 0);
        $this->assertSame(RemoteTaskStatus::Succeeded, (new FakeComputeProvider)->getTask('pve-01', $operation->taskId)->status);
    }

    #[Test]
    public function the_same_request_always_produces_the_same_task_id(): void
    {
        $first = $this->provider->createVirtualMachine($this->request())->taskId;
        $second = (new FakeComputeProvider)->createVirtualMachine($this->request())->taskId;

        // The start-time field is the only part that may differ, so compare
        // everything else: a retried create has to be recognisable as the same
        // job rather than looking like a second one.
        $this->assertSame($this->withoutStartTime($first), $this->withoutStartTime($second));
    }

    #[Test]
    public function power_operations_move_the_machine_and_report_a_task(): void
    {
        $this->provider->createVirtualMachine($this->request());

        $this->assertSame(PowerState::Running, $this->provider->getVm('pve-01', '101')?->powerState);

        $stopped = $this->provider->stopVm('pve-01', '101');
        $this->assertStringStartsWith('UPID:', $stopped->taskId);
        $this->assertSame(PowerState::Stopped, $this->provider->getVm('pve-01', '101')?->powerState);

        $this->provider->startVm('pve-01', '101');
        $this->assertSame(PowerState::Running, $this->provider->getVm('pve-01', '101')?->powerState);

        $this->provider->shutdownVm('pve-01', '101');
        $this->assertSame(PowerState::Stopped, $this->provider->getVm('pve-01', '101')?->powerState);
    }

    #[Test]
    public function an_operation_against_a_machine_that_does_not_exist_fails(): void
    {
        $this->expectException(ComputeProviderException::class);

        $this->provider->rebootVm('pve-01', '404');
    }

    #[Test]
    public function resizing_grows_the_machine_and_never_shrinks_the_disk(): void
    {
        $this->provider->createVirtualMachine($this->request());

        $this->provider->resizeVm('pve-01', '101', new ResizeVmRequest(vcpu: 8, memoryMib: 16384, diskGib: 40));

        $machine = $this->provider->getVm('pve-01', '101');

        $this->assertSame(8, $machine?->vcpu);
        $this->assertSame(16384, $machine?->memoryMib);
        // The disk request is a growth, not an absolute size: 80 + 40.
        $this->assertSame(120, $machine?->diskGib);
    }

    #[Test]
    public function a_resize_with_nothing_to_change_is_refused(): void
    {
        $this->provider->createVirtualMachine($this->request());

        $this->expectException(ComputeProviderException::class);

        $this->provider->resizeVm('pve-01', '101', new ResizeVmRequest);
    }

    #[Test]
    public function destroying_a_machine_makes_it_absent_rather_than_erroring(): void
    {
        $this->provider->createVirtualMachine($this->request());

        $operation = $this->provider->destroyVm('pve-01', '101');

        $this->assertStringStartsWith('UPID:', $operation->taskId);
        // Absence is the answer a destroy is confirmed by.
        $this->assertNull($this->provider->getVm('pve-01', '101'));
        $this->assertSame([], $this->provider->listVms('pve-01'));
    }

    #[Test]
    public function listing_machines_is_ordered_so_that_assertions_are_stable(): void
    {
        foreach ([103, 101, 102] as $vmId) {
            $this->provider->createVirtualMachine($this->request(vmId: $vmId, hostname: 'web-'.$vmId));
        }

        $this->assertSame(
            ['101', '102', '103'],
            array_map(static fn (object $vm): string => $vm->providerId, $this->provider->listVms('pve-01')),
        );
    }

    #[Test]
    public function the_node_inventory_comes_from_configuration(): void
    {
        config()->set('compute.fake.nodes', [[
            'name' => 'pve-99',
            'online' => true,
            'cpu_cores' => 16,
            'memory_total_mib' => 65536,
            'memory_used_mib' => 8192,
            'cpu_usage' => 0.25,
            'storages' => [
                ['name' => 'local-nvme', 'class' => StorageClass::Nvme->value, 'total_gib' => 2048, 'available_gib' => 1024],
            ],
        ]]);

        $nodes = (new FakeComputeProvider)->listNodes();

        $this->assertCount(1, $nodes);
        $this->assertSame('pve-99', $nodes[0]->name);
        $this->assertSame(16, $nodes[0]->cpuCores);
        $this->assertSame(65536, $nodes[0]->memoryTotalMib);
        $this->assertSame(StorageClass::Nvme, $nodes[0]->storages[0]->storageClass);
    }

    #[Test]
    public function the_default_fleet_is_three_nodes_so_spreading_can_be_exercised(): void
    {
        $nodes = $this->provider->listNodes();

        $this->assertCount(3, $nodes);
        $this->assertSame(['pve-01', 'pve-02', 'pve-03'], array_map(
            static fn (object $node): string => $node->name,
            $nodes,
        ));
    }

    private function request(int $vmId = 101, string $hostname = 'web-01'): CreateVmRequest
    {
        return new CreateVmRequest(
            nodeName: 'pve-01',
            vmId: $vmId,
            hostname: $hostname,
            vcpu: 4,
            memoryMib: 8192,
            diskGib: 80,
            storageName: 'local-nvme',
            cloudInit: new CloudInitConfig(sshKeys: ['ssh-ed25519 AAAA test@lynomia']),
        );
    }

    private function withoutStartTime(string $upid): string
    {
        $parts = explode(':', $upid);
        $parts[4] = '';

        return implode(':', $parts);
    }
}
