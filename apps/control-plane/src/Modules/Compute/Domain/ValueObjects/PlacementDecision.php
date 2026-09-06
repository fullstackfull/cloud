<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\ValueObjects;

use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;

/**
 * Where a machine should go, and the whole argument for it.
 *
 * A scheduler that returns only a node is a scheduler nobody can audit. The
 * question an operator actually asks is never "which node" — they can see that
 * — it is "why that one, when this one looks emptier", and the answer has to
 * survive the fleet changing in the minutes before they ask. So the decision
 * carries every candidate's score breakdown and every rejected node's reason,
 * captured at the moment the choice was made.
 *
 * @immutable
 */
final readonly class PlacementDecision
{
    /**
     * @param  list<NodeScore>  $candidates  Every scored node, best first.
     * @param  list<PlacementRejection>  $rejections  Every excluded node, in the order they were excluded.
     * @param  int|null  $antiAffinityLimit  Machines of this customer the chosen node was allowed to
     *                                       hold when the decision was made, or null when the rule was
     *                                       waived because every candidate was already at it. The
     *                                       reservation re-applies it under the node's row lock, so it
     *                                       has to travel with the decision: two of one customer's
     *                                       orders can pass scoring against the same node in the same
     *                                       millisecond, and an exclusion that is only ever evaluated
     *                                       before the lock is advice, not a rule.
     */
    public function __construct(
        public NodeScore $chosen,
        public array $candidates,
        public array $rejections,
        public string $storageName,
        public ?int $antiAffinityLimit = null,
    ) {}

    public function node(): ComputeNode
    {
        return $this->chosen->node;
    }

    public function score(): float
    {
        return $this->chosen->total();
    }

    /**
     * The runner-up, which is what an operator wants when they are deciding
     * whether the winner was a close call or a walkover.
     */
    public function runnerUp(): ?NodeScore
    {
        return $this->candidates[1] ?? null;
    }

    /**
     * The decision as a loggable structure. Persisting this beside the
     * provisioning job is what makes a placement dispute answerable later.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'chosen' => $this->chosen->toArray(),
            'storage' => $this->storageName,
            'anti_affinity_limit' => $this->antiAffinityLimit,
            'candidates' => array_map(
                static fn (NodeScore $score): array => $score->toArray(),
                $this->candidates,
            ),
            'rejections' => array_map(
                static fn (PlacementRejection $rejection): array => $rejection->toArray(),
                $this->rejections,
            ),
        ];
    }
}
