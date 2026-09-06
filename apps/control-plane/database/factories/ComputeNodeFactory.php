<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Lynomia\Modules\Compute\Domain\Enums\NodeStatus;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;

/**
 * @extends Factory<ComputeNode>
 */
class ComputeNodeFactory extends Factory
{
    protected $model = ComputeNode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cluster_id' => ComputeCluster::factory(),
            'provider_name' => 'pve-'.Str::lower(Str::random(6)),
            'status' => NodeStatus::Active,
            'cpu_cores' => 32,
            'memory_mib' => 262144,
            'storage_gib' => 8192,
            'allocated_cpu_cores' => 0,
            'allocated_memory_mib' => 0,
            'allocated_storage_gib' => 0,
            'cpu_overcommit_ratio' => 4.0,
            'memory_headroom_percent' => 10,
            'vm_count' => 0,
            'is_healthy' => true,
            'last_seen_at' => now(),
            'capabilities' => ['architecture' => 'x86_64'],
        ];
    }

    public function named(string $providerName): static
    {
        return $this->state(fn (): array => ['provider_name' => $providerName]);
    }

    public function status(NodeStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function unhealthy(): static
    {
        return $this->state(fn (): array => ['is_healthy' => false]);
    }

    public function withCapacity(int $cpuCores, int $memoryMib, int $storageGib): static
    {
        return $this->state(fn (): array => [
            'cpu_cores' => $cpuCores,
            'memory_mib' => $memoryMib,
            'storage_gib' => $storageGib,
        ]);
    }

    /**
     * Capacity already committed to machines, which is what makes a node look
     * busy to the scheduler without having to create the machines themselves.
     */
    public function allocated(int $cpuCores, int $memoryMib, int $storageGib, int $vmCount = 1): static
    {
        return $this->state(fn (): array => [
            'allocated_cpu_cores' => $cpuCores,
            'allocated_memory_mib' => $memoryMib,
            'allocated_storage_gib' => $storageGib,
            'vm_count' => $vmCount,
        ]);
    }

    public function overcommitRatio(float $ratio): static
    {
        return $this->state(fn (): array => ['cpu_overcommit_ratio' => $ratio]);
    }

    public function memoryHeadroomPercent(int $percent): static
    {
        return $this->state(fn (): array => ['memory_headroom_percent' => $percent]);
    }
}
