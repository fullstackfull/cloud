<?php

declare(strict_types=1);

namespace Lynomia\Modules\Compute\Domain\Services;

use Lynomia\Modules\Compute\Domain\DTOs\PlacementRequest;
use Lynomia\Modules\Compute\Domain\Enums\PlacementRejectionReason;
use Lynomia\Modules\Compute\Domain\Exceptions\NoCapacityAvailableException;
use Lynomia\Modules\Compute\Domain\ValueObjects\CapacityAssessment;
use Lynomia\Modules\Compute\Domain\ValueObjects\NodeScore;
use Lynomia\Modules\Compute\Domain\ValueObjects\PlacementDecision;
use Lynomia\Modules\Compute\Domain\ValueObjects\PlacementRejection;
use Lynomia\Modules\Compute\Domain\ValueObjects\ScoreComponent;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeCluster;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeNode;
use Lynomia\Modules\Compute\Infrastructure\Models\ComputeStorage;

/**
 * Decides which hypervisor a machine goes on.
 *
 * This is weighted placement, not first-fit, and the difference is not
 * aesthetic. First-fit fills node 1 until it will not take another machine,
 * then starts on node 2. The result is a fleet where one node carries every
 * customer who bought in the first week — the maximum possible blast radius —
 * while the rest sit idle, and where the first node is also the one with no
 * headroom left to absorb a failure anywhere else.
 *
 * Eligibility and preference are kept strictly apart:
 *
 *  - a node that is draining, in maintenance, offline, unhealthy, out of
 *    memory, past its CPU overcommit ratio or without storage of the required
 *    class is EXCLUDED. It is never merely given a low score, because a low
 *    score still wins when it is the only score, and "the only node left" is
 *    exactly the situation in which placing on a draining node does the most
 *    damage;
 *  - everything else is a weighted preference, tuned from config.
 *
 * The score is dominated by free memory AFTER this machine lands, because that
 * is the constraint that actually binds on a hypervisor: CPU can be
 * overcommitted and degrades gracefully, memory cannot and does not.
 *
 * Anti-affinity is treated as a correctness requirement rather than a
 * preference: a customer who buys three machines for redundancy and gets three
 * on one node has bought none, and neither they nor the platform finds out
 * until the node fails.
 */
final readonly class NodeScheduler
{
    /**
     * Used when config is missing entirely. The relative sizes carry the
     * policy: memory outweighs everything, and spreading outweighs squeezing.
     *
     * @var array<string, int>
     */
    private const array FALLBACK_WEIGHTS = [
        'memory_headroom' => 40,
        'cpu_headroom' => 20,
        'storage_headroom' => 15,
        'spread' => 15,
        'anti_affinity' => 10,
    ];

    private const int FALLBACK_MAX_CUSTOMER_VMS_PER_NODE = 1;

    public function __construct(
        private NodeCapacityPolicy $policy,
        private CustomerNodeCensus $census,
    ) {}

    /**
     * @throws NoCapacityAvailableException
     */
    public function place(PlacementRequest $request): PlacementDecision
    {
        $cluster = ComputeCluster::query()->find($request->clusterId);

        /*
         * Checked before the nodes are even read. A cluster in maintenance is
         * about to have its whole control plane upgraded, and its nodes will
         * each look perfectly healthy right up until they are not — so this
         * has to be a refusal rather than something the scoring could
         * outweigh.
         */
        if ($cluster === null || ! $cluster->acceptsPlacement()) {
            throw NoCapacityAvailableException::inCluster(
                $request->clusterId,
                $request->resources->vcpu,
                $request->resources->memoryMib,
                $request->resources->diskGib,
                ['cluster_not_accepting_placement' => 1],
            );
        }

        /** @var list<ComputeNode> $nodes */
        $nodes = ComputeNode::query()
            ->where('cluster_id', $request->clusterId)
            // A stable secondary order so that two nodes which genuinely tie
            // resolve the same way on every run; an unordered tie-break makes
            // placement irreproducible and its bugs unrepeatable.
            ->orderBy('id')
            ->get()
            ->all();

        $storages = $this->storagesFor($request->clusterId);

        /** @var list<PlacementRejection> $rejections */
        $rejections = [];

        /** @var list<array{node: ComputeNode, assessment: CapacityAssessment, storage: ComputeStorage}> $eligible */
        $eligible = [];

        foreach ($nodes as $node) {
            $nodeId = (string) $node->getKey();

            if (in_array($nodeId, $request->excludedNodeIds, true)) {
                $rejections[] = new PlacementRejection(
                    $nodeId,
                    $node->provider_name,
                    PlacementRejectionReason::Excluded,
                    'the caller excluded this node',
                );

                continue;
            }

            if ($request->affinityNodeIds !== [] && ! in_array($nodeId, $request->affinityNodeIds, true)) {
                $rejections[] = new PlacementRejection(
                    $nodeId,
                    $node->provider_name,
                    PlacementRejectionReason::NotInAffinityGroup,
                    'the request is pinned to a different set of nodes',
                );

                continue;
            }

            $assessment = $this->policy->assess($node, $request->resources, $request->architecture);

            if (! $assessment->fits) {
                $rejections[] = new PlacementRejection(
                    $nodeId,
                    $node->provider_name,
                    // Guaranteed non-null: a rejected assessment always
                    // carries the reason it was rejected for.
                    $assessment->reason ?? PlacementRejectionReason::Excluded,
                    (string) $assessment->detail,
                );

                continue;
            }

            $storage = $this->storageFor($storages, $nodeId, $request);

            if ($storage === null) {
                $rejections[] = new PlacementRejection(
                    $nodeId,
                    $node->provider_name,
                    $this->storageRejectionReason($storages, $nodeId, $request),
                    sprintf('no active %s pool on this node can take %d GiB', $request->storageClass->value, $request->resources->diskGib),
                );

                continue;
            }

            $eligible[] = ['node' => $node, 'assessment' => $assessment, 'storage' => $storage];
        }

        $affinityCounts = $this->census->countByNode(
            $request->customerId,
            array_map(static fn (array $candidate): string => (string) $candidate['node']->getKey(), $eligible),
        );

        $limit = $this->antiAffinityLimit();

        [$eligible, $rejections, $enforcedLimit] = $this->applyAntiAffinity($eligible, $rejections, $affinityCounts, $limit);

        if ($eligible === []) {
            throw NoCapacityAvailableException::inCluster(
                $request->clusterId,
                $request->resources->vcpu,
                $request->resources->memoryMib,
                $request->resources->diskGib,
                $this->tally($rejections),
            );
        }

        $candidates = $this->score($eligible, $affinityCounts, $request);

        $chosen = $candidates[0];
        $storageName = '';

        foreach ($eligible as $candidate) {
            if ($candidate['node']->is($chosen->node)) {
                $storageName = $candidate['storage']->provider_name;
            }
        }

        return new PlacementDecision($chosen, $candidates, $rejections, $storageName, $enforcedLimit);
    }

    /**
     * @param  list<array{node: ComputeNode, assessment: CapacityAssessment, storage: ComputeStorage}>  $eligible
     * @param  array<string, int>  $affinityCounts
     * @return list<NodeScore>
     */
    private function score(array $eligible, array $affinityCounts, PlacementRequest $request): array
    {
        $weights = $this->weights();

        // Spread is relative: it asks "is this node busier than the busiest
        // candidate?", so that the answer stays meaningful whether the fleet
        // holds ten machines or ten thousand.
        $busiest = max(array_map(
            static fn (array $candidate): int => $candidate['node']->vm_count,
            $eligible,
        ));

        $scores = [];

        foreach ($eligible as $candidate) {
            $node = $candidate['node'];
            $assessment = $candidate['assessment'];
            $customerVms = $affinityCounts[(string) $node->getKey()] ?? 0;

            $scores[] = new NodeScore($node, [
                new ScoreComponent(
                    'memory_headroom',
                    // Scored on what is left AFTER this machine lands, not on
                    // what is free now: the two rank a heterogeneous fleet
                    // differently, and only the second one is the constraint
                    // the node will actually run into.
                    $assessment->memoryHeadroomRatio(),
                    $weights['memory_headroom'],
                    sprintf(
                        '%d MiB of %d schedulable free now, %d MiB after this machine',
                        $assessment->freeMemoryMibAfter + $request->resources->memoryMib,
                        $assessment->schedulableMemoryMib,
                        $assessment->freeMemoryMibAfter,
                    ),
                ),
                new ScoreComponent(
                    'cpu_headroom',
                    $assessment->cpuHeadroomRatio(),
                    $weights['cpu_headroom'],
                    sprintf('%d virtual cores free after placement', $assessment->freeCpuCoresAfter),
                ),
                new ScoreComponent(
                    'storage_headroom',
                    $assessment->storageHeadroomRatio(),
                    $weights['storage_headroom'],
                    sprintf('%d GiB free after placement', $assessment->freeStorageGibAfter),
                ),
                new ScoreComponent(
                    'spread',
                    $busiest < 1 ? 1.0 : 1 - ($node->vm_count / $busiest),
                    $weights['spread'],
                    sprintf('%d machines here against %d on the busiest candidate', $node->vm_count, $busiest),
                ),
                new ScoreComponent(
                    // Decays rather than drops to zero, so that a customer who
                    // already has a machine everywhere still gets the node
                    // where they have fewest.
                    'anti_affinity',
                    1 / (1 + $customerVms),
                    $weights['anti_affinity'],
                    sprintf('%d machine(s) for this customer already here', $customerVms),
                ),
            ], $assessment);
        }

        usort($scores, static function (NodeScore $a, NodeScore $b): int {
            return [$b->total(), (string) $a->node->getKey()] <=> [$a->total(), (string) $b->node->getKey()];
        });

        return $scores;
    }

    /**
     * Hard anti-affinity, with a deliberate fallback.
     *
     * Up to the configured limit per node the rule is an exclusion. Past it —
     * when every remaining candidate already holds that many of this
     * customer's machines — it degrades to the scoring term instead of
     * refusing the order, because a customer with more machines than the
     * cluster has nodes has to share eventually, and telling them their order
     * cannot be placed would be worse than telling them where it landed.
     *
     * The limit it returns is the one the reservation must re-apply under the
     * node's row lock. Null means the rule was waived for this placement, and
     * re-applying it at commit time would refuse an order the scheduler
     * deliberately allowed.
     *
     * @param  list<array{node: ComputeNode, assessment: CapacityAssessment, storage: ComputeStorage}>  $eligible
     * @param  list<PlacementRejection>  $rejections
     * @param  array<string, int>  $affinityCounts
     * @return array{0: list<array{node: ComputeNode, assessment: CapacityAssessment, storage: ComputeStorage}>, 1: list<PlacementRejection>, 2: int|null}
     */
    private function applyAntiAffinity(array $eligible, array $rejections, array $affinityCounts, int $limit): array
    {
        if ($eligible === []) {
            return [$eligible, $rejections, $limit];
        }

        $counts = array_map(
            static fn (array $candidate): int => $affinityCounts[(string) $candidate['node']->getKey()] ?? 0,
            $eligible,
        );

        if (min($counts) >= $limit) {
            return [$eligible, $rejections, null];
        }

        $kept = [];

        foreach ($eligible as $candidate) {
            $count = $affinityCounts[(string) $candidate['node']->getKey()] ?? 0;

            if ($count < $limit) {
                $kept[] = $candidate;

                continue;
            }

            $rejections[] = new PlacementRejection(
                (string) $candidate['node']->getKey(),
                $candidate['node']->provider_name,
                PlacementRejectionReason::AntiAffinity,
                sprintf('this customer already has %d machine(s) here, and the limit is %d', $count, $limit),
            );
        }

        return [$kept, $rejections, $limit];
    }

    /**
     * Machines of one customer a single node may hold.
     */
    private function antiAffinityLimit(): int
    {
        return max(1, (int) config(
            'compute.scheduler.max_customer_vms_per_node',
            self::FALLBACK_MAX_CUSTOMER_VMS_PER_NODE,
        ));
    }

    /**
     * Every pool in the cluster, loaded once.
     *
     * One query rather than a relation per node: placement runs on the hot
     * path of every order, and a fleet of forty nodes would otherwise issue
     * forty round trips to answer one question.
     *
     * @return list<ComputeStorage>
     */
    private function storagesFor(string $clusterId): array
    {
        /** @var list<ComputeStorage> $storages */
        $storages = ComputeStorage::query()
            ->where('cluster_id', $clusterId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->all();

        return $storages;
    }

    /**
     * @param  list<ComputeStorage>  $storages
     */
    private function storageFor(array $storages, string $nodeId, PlacementRequest $request): ?ComputeStorage
    {
        foreach ($storages as $storage) {
            if ($storage->storage_class === $request->storageClass && $storage->canHost($nodeId, $request->resources->diskGib)) {
                return $storage;
            }
        }

        return null;
    }

    /**
     * Distinguishes "this node has no NVMe at all" from "its NVMe pool is
     * full", because only one of them will ever resolve itself.
     *
     * @param  list<ComputeStorage>  $storages
     */
    private function storageRejectionReason(array $storages, string $nodeId, PlacementRequest $request): PlacementRejectionReason
    {
        foreach ($storages as $storage) {
            if ($storage->storage_class !== $request->storageClass) {
                continue;
            }

            if ($storage->node_id === null || $storage->node_id === $nodeId) {
                return PlacementRejectionReason::InsufficientStorage;
            }
        }

        return PlacementRejectionReason::NoStorageOfRequiredClass;
    }

    /**
     * @return array<string, int>
     */
    private function weights(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = config('compute.scheduler.weights', []);

        $weights = [];

        foreach (self::FALLBACK_WEIGHTS as $name => $fallback) {
            $value = $configured[$name] ?? $fallback;
            // Negative weights would invert a term — a node scoring worse for
            // having more free memory — which is never a tuning decision
            // anybody makes on purpose.
            $weights[$name] = is_numeric($value) ? max(0, (int) $value) : $fallback;
        }

        return $weights;
    }

    /**
     * @param  list<PlacementRejection>  $rejections
     * @return array<string, int>
     */
    private function tally(array $rejections): array
    {
        $tally = [];

        foreach ($rejections as $rejection) {
            $tally[$rejection->reason->value] = ($tally[$rejection->reason->value] ?? 0) + 1;
        }

        return $tally;
    }
}
