<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\ValueObjects;

use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;

/**
 * A candidate node and why it scored what it scored.
 *
 * @immutable
 */
final readonly class NodeScore
{
    /**
     * @param  list<ScoreComponent>  $components
     */
    public function __construct(
        public ComputeNode $node,
        public array $components,
        public CapacityAssessment $assessment,
    ) {}

    public function total(): float
    {
        return array_sum(array_map(
            static fn (ScoreComponent $component): float => $component->contribution(),
            $this->components,
        ));
    }

    public function component(string $name): ?ScoreComponent
    {
        foreach ($this->components as $component) {
            if ($component->name === $name) {
                return $component;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $components = [];

        foreach ($this->components as $component) {
            $components[$component->name] = $component->toArray();
        }

        return [
            'node_id' => (string) $this->node->getKey(),
            'node' => $this->node->provider_name,
            'total' => round($this->total(), 4),
            'components' => $components,
            'free_memory_mib_after' => $this->assessment->freeMemoryMibAfter,
            'free_cpu_cores_after' => $this->assessment->freeCpuCoresAfter,
            'free_storage_gib_after' => $this->assessment->freeStorageGibAfter,
        ];
    }
}
