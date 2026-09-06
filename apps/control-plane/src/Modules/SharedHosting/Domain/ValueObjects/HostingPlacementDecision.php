<?php

declare(strict_types=1);

namespace Lynomia\Modules\SharedHosting\Domain\ValueObjects;

use Lynomia\Modules\SharedHosting\Infrastructure\Models\HostingNode;

/**
 * Where an account is to be placed, and everything the scheduler considered.
 *
 * The runners-up and the rejections are carried, not discarded. A placement is
 * re-examined only when it turns out badly — a node that filled up faster than
 * expected, a customer complaining about a neighbour — and by then the fleet's
 * numbers have all moved. Without the decision recorded as it was made there
 * is nothing left to explain it with.
 *
 * @immutable
 */
final readonly class HostingPlacementDecision
{
    /**
     * @param  list<HostingNodeScore>  $candidates  Best first; the chosen node is the head.
     * @param  list<HostingPlacementRejection>  $rejections
     */
    public function __construct(
        public HostingNodeScore $chosen,
        public array $candidates,
        public array $rejections,
    ) {}

    public function node(): HostingNode
    {
        return $this->chosen->node;
    }

    public function score(): float
    {
        return $this->chosen->total();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'node_id' => (string) $this->node()->getKey(),
            'node' => $this->node()->hostname,
            'score' => round($this->score(), 4),
            'candidates' => array_map(
                static fn (HostingNodeScore $score): array => $score->toArray(),
                $this->candidates,
            ),
            'rejections' => array_map(
                static fn (HostingPlacementRejection $rejection): array => $rejection->toArray(),
                $this->rejections,
            ),
        ];
    }
}
