<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Lynomia\Modules\Compute\Domain\Enums\StorageClass;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;

/**
 * @extends Factory<ComputeStorage>
 */
class ComputeStorageFactory extends Factory
{
    protected $model = ComputeStorage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cluster_id' => ComputeCluster::factory(),
            'node_id' => null,
            'provider_name' => 'local-nvme',
            'storage_class' => StorageClass::Nvme,
            'shared' => false,
            'total_gib' => 8192,
            'available_gib' => 8192,
            'is_active' => true,
        ];
    }

    /**
     * Local storage, pinned to one node: a machine placed on it cannot move
     * without copying its disk.
     */
    public function onNode(ComputeNode $node): static
    {
        return $this->state(fn (): array => [
            'cluster_id' => $node->cluster_id,
            'node_id' => $node->getKey(),
            'shared' => false,
        ]);
    }

    public function shared(): static
    {
        return $this->state(fn (): array => [
            'node_id' => null,
            'shared' => true,
            'provider_name' => 'ceph-pool',
            'storage_class' => StorageClass::Ceph,
        ]);
    }

    public function class(StorageClass $class): static
    {
        return $this->state(fn (): array => ['storage_class' => $class]);
    }

    public function available(int $gib): static
    {
        return $this->state(fn (): array => ['available_gib' => $gib]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
