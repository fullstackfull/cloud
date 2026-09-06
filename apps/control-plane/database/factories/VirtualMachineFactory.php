<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Domain\Enums\PowerState;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\VirtualMachine;
use Lynomia\Modules\Provisioning\Infrastructure\Models\Service;

/**
 * @extends Factory<VirtualMachine>
 */
class VirtualMachineFactory extends Factory
{
    protected $model = VirtualMachine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'cluster_id' => null,
            'node_id' => null,
            'template_id' => null,
            // A machine the hypervisor has not confirmed yet: the row exists
            // before the create call returns, which is what makes a lost
            // response reconcilable.
            'provider_id' => null,
            'hostname' => 'vps-'.Str::lower(Str::random(8)),
            'vcpu' => 2,
            'memory_mib' => 4096,
            'disk_gib' => 80,
            'power_state' => PowerState::Unknown,
            'has_drift' => false,
        ];
    }

    /**
     * A built machine, placed on a node and confirmed by the hypervisor.
     */
    public function onNode(ComputeNode $node, ?int $vmId = null): static
    {
        return $this->state(fn (): array => [
            'cluster_id' => $node->cluster_id,
            'node_id' => $node->getKey(),
            'provider_id' => (string) ($vmId ?? random_int(100, 999999)),
            'power_state' => PowerState::Running,
        ]);
    }

    public function forService(Service $service): static
    {
        return $this->state(fn (): array => ['service_id' => $service->getKey()]);
    }

    public function resources(int $vcpu, int $memoryMib, int $diskGib): static
    {
        return $this->state(fn (): array => [
            'vcpu' => $vcpu,
            'memory_mib' => $memoryMib,
            'disk_gib' => $diskGib,
        ]);
    }

    public function drifted(): static
    {
        return $this->state(fn (): array => [
            'has_drift' => true,
            'drift_details' => ['vcpu' => ['expected' => 2, 'observed' => 4]],
            'last_reconciled_at' => now(),
        ]);
    }
}
