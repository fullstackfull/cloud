<?php

declare(strict_types=1);

namespace Tests\Feature\Vps;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\NodeCapacityReservation;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Identity\Domain\Enums\CustomerRole;
use Lynomia\Modules\Identity\Infrastructure\Models\User;
use Lynomia\Modules\Ipam\Domain\Enums\IpAddressStatus;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAddress;
use Lynomia\Modules\Ipam\Infrastructure\Models\IpAssignment;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobKind;
use Lynomia\Modules\Provisioning\Domain\Enums\ProvisioningJobStatus;
use Lynomia\Modules\Provisioning\Domain\Enums\ServiceStatus;
use Lynomia\Modules\Provisioning\Infrastructure\Models\ProvisioningJob;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;
use Lynomia\Modules\Vps\Application\Actions\TerminateVpsService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Vps\Concerns\DrivesVpsCreatesThroughTheOperatorPath;
use Tests\TestCase;

/**
 * An adopted VPS is a machine the platform manages like any other (D7-3,
 * round seven).
 *
 * A create whose answer was lost stops for review, and the operator adopts
 * the machine at the node. Adoption delivered the service and moved the
 * node commitment, and wrote no `virtual_machines` row: the machine could
 * not be resized, destroyed or terminated, its customer could not see it,
 * and TerminateVpsService refused the service with "adopt what exists" - to
 * an operator who had. Adoption now writes the row from what the hypervisor
 * reports where the machine is.
 *
 * And the hypervisor is asked where the machine is before the adoption's
 * transaction, not inside it holding the job's lock (X7-3); the answer is
 * re-checked under the lock.
 */
final class AnAdoptedVpsIsManagedLikeAnyOtherTest extends TestCase
{
    use DrivesVpsCreatesThroughTheOperatorPath;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->setUpTheCluster();
    }

    #[Test]
    public function an_adopted_vps_is_terminated_destroyed_and_its_capacity_given_back(): void
    {
        [$job, $service] = $this->anAdoptedBuild();

        $machine = VirtualMachine::query()->sole();
        $remote = $this->hypervisor->everyMachine()[0];
        $this->assertSame(
            [$service->id, (string) $this->cluster->id, (string) $this->node->id, $remote->providerId, 'web-01', 2, 4096, 40, 'local-nvme', 'debian'],
            [$machine->service_id, $machine->cluster_id, $machine->node_id, $machine->provider_id, $machine->hostname, $machine->vcpu, $machine->memory_mib, $machine->disk_gib, $machine->storage_name, $machine->os_family],
        );
        $this->assertSame($remote->powerState, $machine->power_state);
        $this->assertTrue($job->refresh()->result['adoption']['capacity']['machine_recorded'] ?? null, json_encode($job->result['adoption'] ?? null));

        // Its customer sees it.
        $user = User::factory()->create();
        $this->customer->members()->create(['user_id' => $user->id, 'role' => CustomerRole::Owner, 'accepted_at' => now()]);
        $this->actingAs($user)->getJson('/api/v1/vps')->assertOk()->assertJsonPath('data.0.id', $machine->id);

        // Suspended past its retention window, and terminated.
        $service->refresh()->forceFill(['status' => ServiceStatus::Suspended, 'suspended_at' => now()->subDays(60)])->save();
        $destroy = app(TerminateVpsService::class)->execute($service->refresh());
        $this->assertInstanceOf(ProvisioningJob::class, $destroy);
        $this->assertSame(ProvisioningJobKind::DestroyVps, $destroy->kind);

        $this->runWorker($destroy);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $destroy->refresh()->status, (string) $destroy->last_error);
        $this->assertSame([], $this->hypervisor->everyMachine());
        $this->assertSame(0, VirtualMachine::query()->count());
        $this->assertSame(ServiceStatus::Terminated, $service->refresh()->status);
        $this->assertSame(0, NodeCapacityReservation::query()->whereNull('released_at')->count());
        $node = $this->node->refresh();
        $this->assertSame([0, 0, 0, 0], [(int) $node->vm_count, (int) $node->allocated_cpu_cores, (int) $node->allocated_memory_mib, (int) $node->allocated_storage_gib]);
    }

    #[Test]
    public function an_adopted_vps_can_be_resized(): void
    {
        $this->anAdoptedBuild();
        $machine = VirtualMachine::query()->sole();

        $resize = ProvisioningJob::factory()->create([
            'service_id' => $machine->service_id,
            'customer_id' => $this->customer->id,
            'kind' => ProvisioningJobKind::Resize,
            'provider' => 'fake',
            'status' => ProvisioningJobStatus::Queued,
            'payload' => ['virtual_machine_id' => (string) $machine->id, 'vcpu' => 4, 'memory_mib' => 8192, 'disk_gib' => 80],
        ]);
        $this->runWorker($resize);

        $this->assertSame(ProvisioningJobStatus::Succeeded, $resize->refresh()->status, (string) $resize->last_error);
        $this->assertSame(8192, $this->hypervisor->everyMachine()[0]->memoryMib);
        $this->assertSame(8192, NodeCapacityReservation::query()->whereNull('released_at')->sole()->memory_mib);
    }

    #[Test]
    public function the_adopted_address_is_given_back_when_the_adopted_machine_is_destroyed(): void
    {
        [, $service] = $this->anAdoptedBuild();
        $address = IpAddress::query()->where('status', IpAddressStatus::Quarantined)->sole();

        $this->actingAs($this->operator())
            ->postJson('/api/admin/infrastructure/ip-addresses/'.$address->id.'/adopt', [
                'evidence' => 'The adopted machine answers on this address on net0.',
            ])
            ->assertOk();

        $machine = VirtualMachine::query()->sole();
        $assignment = IpAssignment::query()->where('ip_address_id', $address->id)->sole();
        $this->assertSame([$machine->getMorphClass(), $machine->id], [$assignment->assignable_type, $assignment->assignable_id]);

        $service->refresh()->forceFill(['status' => ServiceStatus::Suspended, 'suspended_at' => now()->subDays(60)])->save();
        $destroy = app(TerminateVpsService::class)->execute($service->refresh());
        $this->assertNotNull($destroy);
        $this->runWorker($destroy);

        $this->assertFalse($assignment->refresh()->isLive(), 'The adopted address outlived the machine it was adopted onto.');
        $this->assertSame(IpAddressStatus::Quarantined, $address->refresh()->status);
    }

    #[Test]
    public function a_machine_the_hypervisor_cannot_find_is_adopted_without_a_row_and_the_record_says_why(): void
    {
        $this->hypervisor->loseTheAnswerToCreates = true;
        $job = $this->createJob();
        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);

        // The operator names a reference the hypervisor does not have.
        $this->adoptAsOperator($job, '99999')->assertOk();

        $this->assertSame(0, VirtualMachine::query()->count());
        $capacity = $job->refresh()->result['adoption']['capacity'] ?? [];
        $this->assertFalse($capacity['machine_recorded'] ?? null);
        $this->assertStringContainsString('not found', (string) ($capacity['machine_reason'] ?? ''));
    }

    #[Test]
    public function the_hypervisor_is_asked_before_the_adoptions_transaction(): void
    {
        $this->hypervisor->loseTheAnswerToCreates = true;
        $job = $this->createJob();
        $this->runWorker($job);
        $machine = $this->hypervisor->everyMachine()[0];

        // Every read of the machine, not only the first, records where it was made.
        $outside = DB::transactionLevel();
        $asked = [];
        $look = function () use (&$asked, &$look): void {
            $asked[] = DB::transactionLevel();
            $this->hypervisor->atTheMomentOfLook = $look;
        };
        $this->hypervisor->atTheMomentOfLook = $look;

        $this->adoptAsOperator($job, $machine->providerId)->assertOk();
        $this->hypervisor->atTheMomentOfLook = null;

        $this->assertNotSame([], $asked, 'The hypervisor was never asked.');
        $this->assertSame([$outside], array_values(array_unique($asked)), 'The hypervisor was asked inside a transaction the adoption holds, with its locks.');
        $this->assertSame(1, VirtualMachine::query()->count());
    }

    #[Test]
    public function an_answer_about_a_cluster_the_build_no_longer_names_is_not_acted_on(): void
    {
        $this->hypervisor->loseTheAnswerToCreates = true;
        $job = $this->createJob();
        $this->runWorker($job);
        $machine = $this->hypervisor->everyMachine()[0];
        $elsewhere = ComputeCluster::factory()->create(['driver' => 'fake']);

        // Between the look and the lock, the build is moved to another cluster.
        $moved = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$moved, $job, $elsewhere): void {
            if ($moved || ! str_contains($query, 'pg_advisory_xact_lock')) {
                return;
            }
            $moved = true;
            DB::table('provisioning_jobs')->where('id', $job->id)->update(['reserved_cluster_id' => $elsewhere->id]);
        });

        $this->adoptAsOperator($job, $machine->providerId)->assertOk();

        $this->assertTrue($moved);
        $this->assertSame(0, VirtualMachine::query()->count());
        $this->assertStringContainsString('cluster changed', (string) ($job->refresh()->result['adoption']['capacity']['machine_reason'] ?? ''));
    }

    #[Test]
    public function an_answer_naming_a_node_no_longer_in_the_cluster_is_not_acted_on(): void
    {
        $this->hypervisor->loseTheAnswerToCreates = true;
        $job = $this->createJob();
        $this->runWorker($job);
        $machine = $this->hypervisor->everyMachine()[0];
        $elsewhere = ComputeCluster::factory()->create(['driver' => 'fake']);

        // Between the look and the lock, the node it was found on leaves the cluster.
        $moved = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$moved, $elsewhere): void {
            if ($moved || ! str_contains($query, 'pg_advisory_xact_lock')) {
                return;
            }
            $moved = true;
            DB::table('compute_nodes')->where('id', $this->node->id)->update(['cluster_id' => $elsewhere->id]);
        });

        $this->adoptAsOperator($job, $machine->providerId)->assertOk();

        $this->assertTrue($moved);
        $this->assertSame(0, VirtualMachine::query()->count());
        $this->assertStringContainsString('no longer recorded', (string) ($job->refresh()->result['adoption']['capacity']['machine_reason'] ?? ''));
    }

    /**
     * A create whose answer was lost, adopted by the operator.
     *
     * @return array{ProvisioningJob, Service}
     */
    private function anAdoptedBuild(): array
    {
        $this->hypervisor->loseTheAnswerToCreates = true;
        $job = $this->createJob();
        $this->runWorker($job);
        $this->assertSame(ProvisioningJobStatus::NeedsReview, $job->refresh()->status);
        $this->assertSame(0, VirtualMachine::query()->count());
        $this->hypervisor->loseTheAnswerToCreates = false;

        $this->adoptAsOperator($job, $this->hypervisor->everyMachine()[0]->providerId)->assertOk();
        $this->assertSame(ProvisioningJobStatus::Succeeded, $job->refresh()->status);

        return [$job, Service::query()->findOrFail($job->service_id)];
    }
}
