<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\ValueObjects;

use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * A candidate node and why it scored what it scored.
 *
 * @immutable
 */
final readonly class HostingNodeScore
{
    /**
     * @param  list<HostingScoreComponent>  $components
     */
    public function __construct(
        public HostingNode $node,
        public array $components,
    ) {}

    public function total(): float
    {
        return array_sum(array_map(
            static fn (HostingScoreComponent $component): float => $component->contribution(),
            $this->components,
        ));
    }

    public function component(string $name): ?HostingScoreComponent
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
            'node' => $this->node->hostname,
            'total' => round($this->total(), 4),
            'components' => $components,
        ];
    }
}
